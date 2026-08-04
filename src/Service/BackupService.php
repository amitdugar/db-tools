<?php

declare(strict_types=1);

namespace DbTools\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Config\BackupOptions;
use DbTools\Config\DatabaseConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class BackupService implements BackupServiceInterface
{
    private readonly TempFileRegistry $tempRegistry;

    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
        ?TempFileRegistry $tempRegistry = null,
        private readonly BinaryResolver $binaries = new BinaryResolver(),
    ) {
        $this->tempRegistry = $tempRegistry ?? new TempFileRegistry();
    }

    /**
     * @param array<string,mixed> $options
     */
    public function backup(array $options, ?callable $tickCallback = null): string
    {
        $backupOptions = $this->fromArray($options);
        $db = $backupOptions->database;

        $this->logger?->info('Starting backup', ['db' => $db->database]);

        $timestamp = gmdate('Ymd-His');
        $note = $backupOptions->note ? '-' . $this->slug($backupOptions->note) : '';
        $label = $backupOptions->label ?? $db->database;

        // Decide the encryption passphrase and whether a random token is embedded in
        // the filename. Two schemes, selected by whether the caller gave an explicit
        // password:
        $randomString = null;
        $encryptionPassword = null;
        if ($backupOptions->encryptionPassword !== null && $backupOptions->encryptionPassword !== '') {
            // Explicit/fixed passphrase (e.g. a caller-managed or escrowed key): use it
            // verbatim. No DB-password derivation and NO random token in the filename —
            // the archive can only be restored by supplying this same password via
            // --encryption-password (it is not reconstructable from the file). This is
            // what makes a backup portable across machines whose DB password differs.
            $encryptionPassword = $backupOptions->encryptionPassword;
        } elseif ($backupOptions->encrypt) {
            // Default scheme (UNCHANGED): DB_PASSWORD + random token, token embedded in
            // the filename so restore reconstructs the passphrase automatically.
            // Existing backups and callers relying on the default are unaffected.
            $randomString = $this->generateRandomString();
            $encryptionPassword = ($db->password ?? '') . $randomString;
        }

        // Build filename with optional random string for encryption
        $destBase = $randomString !== null
            ? sprintf('%s-%s-%s%s.sql', $label, $timestamp, $randomString, $note)
            : sprintf('%s-%s%s.sql', $label, $timestamp, $note);

        $dest = rtrim($backupOptions->outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $destBase;

        // Create it up front so both the streaming and the ZIP path, and the
        // metadata written alongside them, land somewhere that exists. Without
        // this the shell redirect fails and the backup dies with an error that
        // names neither the directory nor the cause.
        $this->ensureDirectory(dirname($dest));

        $backend = $backupOptions->compressionBackend ?? ArchiveUtility::pickBestBackend();

        if ($backend === ArchiveUtility::BACKEND_ZIP) {
            // ZIP is not stream-friendly — fall back to temp file approach
            $archivePath = $this->backupViaTemp($db, $dest, $backend, $encryptionPassword, $backupOptions, $tickCallback);
        } else {
            // Stream: mysqldump | compressor [| gpg] > final
            $archivePath = $this->backupStreaming($db, $dest, $backend, $encryptionPassword, $tickCallback);
        }

        $this->writeMetadata($archivePath, $db, $note, $backupOptions, $randomString !== null);
        $this->applyRetention($backupOptions);

        $this->logger?->info('Backup complete', ['archive' => $archivePath]);
        return $archivePath;
    }

    /**
     * Streaming backup: mysqldump | compressor [| gpg] > output file.
     * Zero temp files on disk.
     */
    private function backupStreaming(
        DatabaseConfig $db,
        string $dest,
        string $backend,
        ?string $encryptionPassword,
        ?callable $tickCallback,
    ): string {
        $dumpCmd = $this->buildDumpCommand($db);
        $compressCmd = $this->buildCompressCommand($backend);
        $ext = ArchiveUtility::extensionForBackend($backend);
        $destWithExt = $dest . $ext;

        $pipeline = [$dumpCmd, $compressCmd];

        if ($encryptionPassword !== null) {
            $gpgDestWithExt = $destWithExt . '.gpg';
            $pipeline[] = [
                'gpg', '--batch', '--yes',
                '--passphrase', $encryptionPassword,
                '--symmetric', '--cipher-algo', 'AES256',
            ];
            // Build shell pipeline that redirects last stage to file
            $this->runPipelineToFile($pipeline, $gpgDestWithExt, $db->password, $tickCallback);
            return $gpgDestWithExt;
        }

        $this->runPipelineToFile($pipeline, $destWithExt, $db->password, $tickCallback);
        return $destWithExt;
    }

    /**
     * Run a pipeline of commands, writing the last stage's stdout to $outputPath.
     *
     * @param list<list<string>> $pipeline
     */
    private function runPipelineToFile(array $pipeline, string $outputPath, ?string $dbPassword, ?callable $tickCallback): void
    {
        // Build shell command: cmd1 | cmd2 | ... > outputPath
        $parts = [];
        foreach ($pipeline as $cmd) {
            $parts[] = implode(' ', array_map('escapeshellarg', $cmd));
        }
        $shell = implode(' | ', $parts) . ' > ' . escapeshellarg($outputPath);

        $env = $dbPassword ? ['MYSQL_PWD' => $dbPassword] : [];
        $fullEnv = $env + getenv();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Register output for cleanup in case of failure
        $this->tempRegistry->register($outputPath);

        $process = proc_open($this->pipefailCommand($shell), $descriptors, $pipes, null, $fullEnv);
        if (!is_resource($process)) {
            $this->tempRegistry->cleanup();
            throw new RuntimeException('Failed to start pipeline');
        }

        fclose($pipes[0]); // close stdin
        fclose($pipes[1]); // we redirect stdout to file via shell

        $closed = false;

        try {
            $running = true;
            while ($running) {
                if ($tickCallback !== null) {
                    $tickCallback();
                }
                usleep(100_000);
                $status = proc_get_status($process);
                $running = $status['running'];
            }

            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);

            // proc_get_status only reports the real code on the first call after
            // the process exits; the polling loop above has already consumed it,
            // so proc_close is what carries the status here.
            $status = proc_get_status($process);
            $exitCode = $status['exitcode'];
            $closeCode = proc_close($process);
            $closed = true;

            if ($exitCode === -1) {
                $exitCode = $closeCode;
            }

            if ($exitCode !== 0) {
                throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : 'Backup pipeline failed');
            }
        } catch (\Throwable $e) {
            if (is_resource($pipes[2])) {
                @fclose($pipes[2]);
            }
            // Only if we did not already close it: proc_close on a closed handle
            // raises a TypeError, which @ does not suppress, and that would
            // replace the real failure with an unrelated fatal.
            if (!$closed) {
                @proc_close($process);
            }
            $this->tempRegistry->cleanup();
            throw $e;
        }

        // Success — release from cleanup registry
        $this->tempRegistry->release($outputPath);
    }

    /**
     * Wrap the pipeline so a failure in any stage fails the whole thing.
     *
     * A shell pipeline reports only its LAST stage's status. Without pipefail a
     * mysqldump that dies — bad credentials, server gone, disk full mid-dump —
     * is invisible, because the compressor downstream happily succeeds on the
     * truncated input and exits 0. The backup is then reported as successful
     * and a valid-looking but incomplete archive is written, which is the worst
     * possible failure mode for a backup tool.
     *
     * pipefail is not in POSIX sh, and /bin/sh is dash on Debian and Ubuntu, so
     * this needs a shell known to support it.
     *
     * @return list<string>|string
     */
    private function pipefailCommand(string $shell): array|string
    {
        foreach (['/bin/bash', '/usr/bin/bash', '/bin/zsh'] as $candidate) {
            if (is_executable($candidate)) {
                return [$candidate, '-o', 'pipefail', '-c', $shell];
            }
        }

        $this->logger?->warning(
            'No pipefail-capable shell found; a failure in an early pipeline stage may go undetected.'
        );

        return $shell;
    }

    private function ensureDirectory(string $dir): void
    {
        // The extra is_dir() guard covers a concurrent backup creating it between
        // the check and the mkdir.
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create output directory: {$dir}");
        }
    }

    /**
     * Fallback for ZIP (not stream-friendly): dump to temp → compress → optional encrypt.
     */
    private function backupViaTemp(
        DatabaseConfig $db,
        string $dest,
        string $backend,
        ?string $encryptionPassword,
        BackupOptions $backupOptions,
        ?callable $tickCallback,
    ): string {
        $tempBase = TempFileRegistry::resolveTempBase(
            isset($backupOptions->tempDir) ? $backupOptions->tempDir : null,
        );
        $tempSql = $this->createTempFile($tempBase, 'dbtools-dump-');
        $this->tempRegistry->register($tempSql);

        try {
            $this->dumpDatabaseToFile($db, $tempSql, $tickCallback);
            $archivePath = $this->compressSql($tempSql, $dest, $backend, $encryptionPassword, $tickCallback);
        } finally {
            $this->tempRegistry->cleanup();
        }

        return $archivePath;
    }

    private function generateRandomString(): string
    {
        $bytes = random_bytes(32);
        return substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, 32);
    }

    /**
     * @return list<string>
     */
    private function buildDumpCommand(DatabaseConfig $db): array
    {
        $cmd = [
            $this->binaries->resolve('mysqldump'),
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            '--single-transaction',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            $db->database,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        return $cmd;
    }

    /**
     * @return list<string>
     */
    private function buildCompressCommand(string $backend): array
    {
        return match ($backend) {
            ArchiveUtility::BACKEND_ZSTD => ['zstd', '-T0', '-q', '-19'],
            ArchiveUtility::BACKEND_PIGZ => ['pigz', '-c'],
            ArchiveUtility::BACKEND_GZIP => ['gzip', '-c'],
            default => throw new RuntimeException("Unsupported streaming backend: {$backend}"),
        };
    }

    private function dumpDatabaseToFile(DatabaseConfig $db, string $path, ?callable $tickCallback = null): void
    {
        $cmd = $this->buildDumpCommand($db);
        $cmd[] = '--result-file=' . $path;

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->runner->run($cmd, $env, $tickCallback);
    }

    private function compressSql(string $src, string $dest, ?string $backend, ?string $password, ?callable $tickCallback = null): string
    {
        $backend ??= ArchiveUtility::pickBestBackend();
        $destWithExt = ArchiveUtility::compressFile($src, $dest, $backend, $tickCallback);

        if ($password) {
            if ($backend === ArchiveUtility::BACKEND_ZIP) {
                $destWithExt = $this->repackZipWithPassword($destWithExt, $password);
            } else {
                $destWithExt = $this->encryptFile($destWithExt, $password, $tickCallback);
            }
        }

        return $destWithExt;
    }

    private function encryptFile(string $path, string $password, ?callable $tickCallback = null): string
    {
        $encryptedPath = $path . '.gpg';

        $cmd = [
            'gpg', '--batch', '--yes',
            '--passphrase', $password,
            '--symmetric', '--cipher-algo', 'AES256',
            '--output', $encryptedPath,
            $path,
        ];

        $this->runner->run($cmd, [], $tickCallback);
        @unlink($path);

        return $encryptedPath;
    }

    private function writeMetadata(string $archivePath, DatabaseConfig $db, string $note, BackupOptions $options, bool $encrypted = false): void
    {
        $meta = [
            'created_at' => gmdate(DATE_ATOM),
            'database' => $db->database,
            'host' => $db->host,
            'port' => $db->port,
            'note' => $note !== '' ? ltrim($note, '-') : null,
            'compression' => pathinfo($archivePath, PATHINFO_EXTENSION),
            'archive' => basename($archivePath),
            'backend' => $options->compressionBackend ?? ArchiveUtility::pickBestBackend(),
            'encrypted' => $encrypted || $options->encryptionPassword !== null,
        ];

        $metaPath = preg_replace('/\.(zst|gz|zip|gpg)$/', '.meta.json', $archivePath) ?: $archivePath . '.meta.json';
        $metaPath = preg_replace('/\.gpg\.meta\.json$/', '.meta.json', $metaPath) ?: $metaPath;
        @file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function applyRetention(BackupOptions $options): void
    {
        if ($options->retention === null || $options->retention < 1) {
            return;
        }

        $pattern = sprintf('/^%s-\\d{8}-\\d{6}.*\\.sql\\.(zst|gz|zip)(\\.gpg)?$/', preg_quote($options->label ?? $options->database->database, '/'));
        $files = glob(rtrim($options->outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql.*') ?: [];

        $matches = array_values(array_filter($files, static fn($file) => preg_match($pattern, basename((string) $file))));
        rsort($matches);

        if (count($matches) <= $options->retention) {
            return;
        }

        $toDelete = array_slice($matches, $options->retention);
        foreach ($toDelete as $file) {
            @unlink($file);
            $meta = preg_replace('/\.(zst|gz|zip)(\.gpg)?$/', '.meta.json', $file);
            if ($meta && is_file($meta)) {
                @unlink($meta);
            }
        }
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function createTempFile(string $dir, string $prefix): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException("Unable to create output directory: {$dir}");
        }

        $temp = tempnam($dir, $prefix);
        if ($temp === false) {
            throw new RuntimeException("Unable to create temporary file in {$dir}");
        }

        return $temp;
    }

    private function repackZipWithPassword(string $zipPath, string $password): string
    {
        $tempBase = TempFileRegistry::resolveTempBase();
        $tempDir = $this->tempRegistry->createTempDir($tempBase, 'dbtools-zip-');

        try {
            $extracted = ArchiveUtility::decompressToFile($zipPath, $tempDir);
            @unlink($zipPath);

            $zip = new \ZipArchive();
            $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($result !== true) {
                throw new RuntimeException("Failed to create password-protected zip: {$zipPath}");
            }

            $entryName = basename($extracted);
            $zip->setPassword($password);
            $zip->addFile($extracted, $entryName);
            $zip->setEncryptionName($entryName, \ZipArchive::EM_AES_256);
            $zip->close();
        } finally {
            $this->tempRegistry->cleanup();
        }

        return $zipPath;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function fromArray(array $options): BackupOptions
    {
        foreach (['database', 'output_dir'] as $required) {
            if (!isset($options[$required]) || $options[$required] === '' || $options[$required] === null) {
                throw new RuntimeException("Missing required option: {$required}");
            }
        }

        $db = new DatabaseConfig(
            host: (string) ($options['host'] ?? 'localhost'),
            database: (string) $options['database'],
            user: isset($options['user']) ? (string) $options['user'] : null,
            password: isset($options['password']) ? (string) $options['password'] : null,
            port: isset($options['port']) ? (int) $options['port'] : null,
        );

        return new BackupOptions(
            database: $db,
            outputDir: (string) $options['output_dir'],
            note: isset($options['note']) ? (string) $options['note'] : null,
            retention: isset($options['retention']) ? (int) $options['retention'] : null,
            compressionBackend: isset($options['compression']) ? (string) $options['compression'] : null,
            encryptionPassword: isset($options['encryption_password']) ? (string) $options['encryption_password'] : null,
            label: isset($options['label']) ? (string) $options['label'] : null,
            encrypt: (bool) ($options['encrypt'] ?? false),
        );
    }
}
