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
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function backup(array $options, ?callable $tickCallback = null): string
    {
        $backupOptions = $this->fromArray($options);
        $db = $backupOptions->database;

        $this->logger?->info('Starting backup', ['db' => $db->database]);

        $tempSql = $this->createTempFile(sys_get_temp_dir(), 'dbtools-dump-');
        $this->dumpDatabase($db, $tempSql, $tickCallback);

        $timestamp = gmdate('Ymd-His');
        $note = $backupOptions->note ? '-' . $this->slug($backupOptions->note) : '';
        $label = $backupOptions->label ?? $db->database;

        // Generate random string for encryption (embedded in filename)
        $randomString = null;
        $encryptionPassword = null;
        if ($backupOptions->encrypt || $backupOptions->encryptionPassword !== null) {
            $randomString = $this->generateRandomString();
            // Password = DB_PASSWORD + randomString (VLSM-compatible)
            $encryptionPassword = ($db->password ?? '') . $randomString;
        }

        // Build filename with optional random string for encryption
        $destBase = $randomString !== null
            ? sprintf('%s-%s-%s%s.sql', $label, $timestamp, $randomString, $note)
            : sprintf('%s-%s%s.sql', $label, $timestamp, $note);

        $dest = rtrim($backupOptions->outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $destBase;
        $archivePath = $this->compressSql($tempSql, $dest, $backupOptions->compressionBackend, $encryptionPassword, $tickCallback);

        @unlink($tempSql);
        $this->writeMetadata($archivePath, $db, $note, $backupOptions, $randomString !== null);
        $this->applyRetention($backupOptions);

        $this->logger?->info('Backup complete', ['archive' => $archivePath]);
        return $archivePath;
    }

    private function generateRandomString(): string
    {
        // Generate a secure random string (like VLSM: openssl rand -base64 32 | tr -d '/+=' | head -c 32)
        $bytes = random_bytes(32);
        return substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, 32);
    }

    private function dumpDatabase(DatabaseConfig $db, string $path, ?callable $tickCallback = null): void
    {
        $cmd = [
            'mysqldump',
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            '--single-transaction',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--result-file=' . $path,
            $db->database,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

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

        // Use GPG for symmetric AES-256 encryption (VLSM-compatible)
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
        // Handle .gpg extension (e.g., .sql.zst.gpg -> .meta.json)
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
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-zip-' . bin2hex(random_bytes(4));
        if (!@mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new RuntimeException("Unable to create temporary directory: {$tempDir}");
        }

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
        @unlink($extracted);
        @rmdir($tempDir);

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
