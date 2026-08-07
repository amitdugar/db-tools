<?php

declare(strict_types=1);

namespace DbTools\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Config\DatabaseConfig;
use DbTools\Config\RestoreOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RestoreService implements RestoreServiceInterface
{
    private readonly TempFileRegistry $tempRegistry;

    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly BackupServiceInterface $backupService = new NullBackupService(),
        private readonly ?LoggerInterface $logger = null,
        ?TempFileRegistry $tempRegistry = null,
        private readonly BinaryResolver $binaries = new BinaryResolver(),
    ) {
        $this->tempRegistry = $tempRegistry ?? new TempFileRegistry();
    }

    /**
     * @param array<string,mixed> $options
     * @return array{archive_size: int, sql_size: int} File sizes for display
     */
    public function restore(array $options, ?callable $tickCallback = null): array
    {
        $restoreOptions = $this->fromArray($options);
        $db = $restoreOptions->database;
        $this->logger?->info('Starting restore', ['archive' => $restoreOptions->archive, 'db' => $db->database]);

        // Validate everything needed to open the archive BEFORE anything
        // destructive happens. recreateDatabase() drops the target database, and
        // these checks used to sit after it — so a mistyped path or a missing
        // passphrase dropped the database first and only then reported the
        // problem, leaving nothing to restore from.
        $archive = realpath($restoreOptions->archive) ?: $restoreOptions->archive;
        if (!is_file($archive)) {
            throw new RuntimeException("Archive not found: {$archive}");
        }

        $archiveSize = (int) filesize($archive);
        $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
        $password = $restoreOptions->encryptionPassword ?? $this->derivePasswordFromFilename($archive, $db->password);

        if (in_array($ext, ['gpg', 'enc'], true) && $password === null) {
            throw new RuntimeException(
                'Archive is encrypted; provide --encryption-password or ensure filename contains random string'
            );
        }

        if (!$restoreOptions->skipSafetyBackup) {
            $this->logger?->info('Creating safety backup');
            $this->backupService->backup([
                'database' => $db->database,
                'host' => $db->host,
                'user' => $db->user,
                'password' => $db->password,
                'port' => $db->port,
                'output_dir' => $restoreOptions->outputDir ?? dirname($restoreOptions->archive),
                'label' => 'pre-restore-' . $db->database,
            ], $tickCallback);
        }

        if ($tickCallback !== null) {
            $tickCallback();
        }
        $this->recreateDatabase($db, $tickCallback);
        if ($tickCallback !== null) {
            $tickCallback();
        }

        // Determine if we can stream
        $canStream = $this->canStream($archive, $ext, $password);

        if ($canStream) {
            $this->restoreStreaming($archive, $ext, $password, $db, $tickCallback);
            $sqlSize = 0; // Unknown when streaming — not materialized
        } else {
            // Fallback: extract to temp, import, clean up
            $sqlPath = $this->extractArchive($restoreOptions, $tickCallback);
            $sqlSize = is_file($sqlPath) ? (int) filesize($sqlPath) : 0;
            try {
                $this->importSql($db, $sqlPath, $tickCallback);
            } finally {
                $this->tempRegistry->cleanup();
            }
        }

        $this->logger?->info('Restore finished', ['db' => $db->database]);

        return [
            'archive_size' => $archiveSize,
            'sql_size' => $sqlSize,
        ];
    }

    /**
     * Can we stream this archive directly into mysql without temp files?
     */
    private function canStream(string $archive, string $ext, ?string $password): bool
    {
        // Encrypted .gpg wrapping a .zst or .gz — streamable
        if ($ext === 'gpg' && $password !== null) {
            $inner = strtolower(pathinfo(pathinfo($archive, PATHINFO_FILENAME), PATHINFO_EXTENSION));
            return in_array($inner, ['zst', 'gz'], true);
        }

        // Plain .zst or .gz — streamable
        return in_array($ext, ['zst', 'gz'], true);
    }

    /**
     * Stream: [gpg -d |] decompressor | mysql
     */
    private function restoreStreaming(
        string $archive,
        string $ext,
        ?string $password,
        DatabaseConfig $db,
        ?callable $tickCallback,
    ): void {
        $pipeline = [];

        if ($ext === 'gpg') {
            // canStream() only returns true for .gpg when a password is known,
            // so this cannot fire — but a null here would reach proc_open as a
            // command argument, which is worth failing loudly rather than
            // relying on a caller-side invariant.
            if ($password === null) {
                throw new RuntimeException('Archive is encrypted; provide --encryption-password');
            }

            $pipeline[] = [
                'gpg', '--batch', '--yes',
                '--passphrase', $password,
                '--decrypt', $archive,
            ];
            // Determine inner extension
            $innerExt = strtolower(pathinfo(pathinfo($archive, PATHINFO_FILENAME), PATHINFO_EXTENSION));
        } else {
            $innerExt = $ext;
        }

        $pipeline[] = $this->buildDecompressCommand($innerExt, $ext === 'gpg' ? null : $archive);
        $pipeline[] = $this->buildMysqlCommand($db);

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->runner->runPipeline($pipeline, $env, $tickCallback);
    }

    /**
     * @return list<string>
     */
    private function buildDecompressCommand(string $ext, ?string $inputFile): array
    {
        return match ($ext) {
            'zst' => $inputFile !== null ? ['zstd', '-dc', $inputFile] : ['zstd', '-dc'],
            'gz' => $inputFile !== null ? ['pigz', '-dc', $inputFile] : ['pigz', '-dc'],
            default => throw new RuntimeException("Unsupported streaming decompression: .{$ext}"),
        };
    }

    /**
     * @return list<string>
     */
    private function buildMysqlCommand(DatabaseConfig $db): array
    {
        $cmd = [
            ...$this->binaries->client('mysql', $db->password),
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            $db->database,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        return $cmd;
    }

    private function extractArchive(RestoreOptions $options, ?callable $tickCallback = null): string
    {
        $archive = realpath($options->archive) ?: $options->archive;
        if (!is_file($archive)) {
            throw new RuntimeException("Archive not found: {$archive}");
        }

        $tempBase = TempFileRegistry::resolveTempBase($options->tempDir);
        $tempDir = $this->tempRegistry->createTempDir($tempBase, 'dbtools-restore-');

        $password = $options->encryptionPassword ?? $this->derivePasswordFromFilename($archive, $options->database->password);

        $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));

        // Handle .gpg encrypted files
        if ($ext === 'gpg') {
            if ($password === null) {
                throw new RuntimeException('Archive is encrypted; provide --encryption-password or ensure filename contains random string');
            }
            $archive = $this->decryptFile($archive, $password, $tempDir, $tickCallback);
            $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
        }

        // Handle .enc encrypted files (legacy openssl encrypted)
        if ($ext === 'enc') {
            if ($password === null) {
                throw new RuntimeException('Archive is encrypted; provide --encryption-password');
            }
            $archive = $this->decryptFileLegacy($archive, $password, $tempDir, $tickCallback);
            $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
        }

        // Handle password-protected ZIP
        if ($ext === 'zip' && ArchiveUtility::isPasswordProtected($archive)) {
            if ($password === null) {
                throw new RuntimeException('Archive is password protected; provide --encryption-password');
            }
            return ArchiveUtility::extractPasswordProtectedZip($archive, $password, $tempDir);
        }

        $sqlPath = ArchiveUtility::decompressToFile($archive, $tempDir, $tickCallback);
        $lower = strtolower(pathinfo($sqlPath, PATHINFO_EXTENSION));
        if ($lower !== 'sql') {
            throw new RuntimeException("Expected SQL after extraction, got .{$lower}");
        }

        return $sqlPath;
    }

    private function derivePasswordFromFilename(string $archivePath, ?string $dbPassword): ?string
    {
        $basename = basename($archivePath);

        if (preg_match('/-(\d{8})-(\d{6})-([A-Za-z0-9]{32})/', $basename, $matches)) {
            $randomString = $matches[3];
            return ($dbPassword ?? '') . $randomString;
        }

        return null;
    }

    private function decryptFile(string $encryptedPath, string $password, string $tempDir, ?callable $tickCallback = null): string
    {
        $decryptedPath = $tempDir . DIRECTORY_SEPARATOR . basename($encryptedPath, '.gpg');

        $cmd = [
            'gpg', '--batch', '--yes',
            '--passphrase', $password,
            '--decrypt',
            '--output', $decryptedPath,
            $encryptedPath,
        ];

        $this->runner->run($cmd, [], $tickCallback);

        return $decryptedPath;
    }

    private function decryptFileLegacy(string $encryptedPath, string $password, string $tempDir, ?callable $tickCallback = null): string
    {
        $decryptedPath = $tempDir . DIRECTORY_SEPARATOR . basename($encryptedPath, '.enc');

        $cmd = [
            'openssl', 'enc', '-aes-256-cbc', '-d', '-pbkdf2',
            '-in', $encryptedPath,
            '-out', $decryptedPath,
            '-pass', 'pass:' . $password,
        ];

        $this->runner->run($cmd, [], $tickCallback);

        return $decryptedPath;
    }

    private function recreateDatabase(DatabaseConfig $db, ?callable $tickCallback = null): void
    {
        $commands = [
            sprintf('DROP DATABASE IF EXISTS `%s`;', $db->database),
            sprintf('CREATE DATABASE `%s`;', $db->database),
        ];

        $cmd = [
            ...$this->binaries->client('mysql', $db->password),
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            '-e',
            implode(' ', $commands),
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->runner->run($cmd, $env, $tickCallback);
    }

    private function importSql(DatabaseConfig $db, string $sqlPath, ?callable $tickCallback = null): void
    {
        if (!is_file($sqlPath)) {
            throw new RuntimeException("SQL file not found: {$sqlPath}");
        }

        $cmd = [
            ...$this->binaries->client('mysql', $db->password),
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            $db->database,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->runner->runWithFileInput($cmd, $env, $sqlPath, $tickCallback);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function fromArray(array $options): RestoreOptions
    {
        foreach (['database', 'archive'] as $required) {
            if (!isset($options[$required])) {
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

        return new RestoreOptions(
            database: $db,
            archive: (string) $options['archive'],
            force: (bool) ($options['force'] ?? false),
            skipSafetyBackup: (bool) ($options['skip_safety_backup'] ?? false),
            encryptionPassword: isset($options['encryption_password']) ? (string) $options['encryption_password'] : null,
            tempDir: isset($options['temp_dir']) ? (string) $options['temp_dir'] : null,
            outputDir: isset($options['output_dir']) ? (string) $options['output_dir'] : null,
        );
    }
}
