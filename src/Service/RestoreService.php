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
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly BackupServiceInterface $backupService = new NullBackupService(),
        private readonly ?LoggerInterface $logger = null,
    ) {
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

        // Get archive size before starting
        $archiveSize = is_file($restoreOptions->archive) ? (int) filesize($restoreOptions->archive) : 0;

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

        $sqlPath = $this->extractArchive($restoreOptions, $tickCallback);

        // Get raw SQL file size after extraction
        $sqlSize = is_file($sqlPath) ? (int) filesize($sqlPath) : 0;

        if ($tickCallback !== null) {
            $tickCallback();
        }
        $this->recreateDatabase($db, $tickCallback);
        if ($tickCallback !== null) {
            $tickCallback();
        }
        $this->importSql($db, $sqlPath, $tickCallback);

        if (is_file($sqlPath)) {
            @unlink($sqlPath);
        }

        $this->logger?->info('Restore finished', ['db' => $db->database]);

        return [
            'archive_size' => $archiveSize,
            'sql_size' => $sqlSize,
        ];
    }

    private function extractArchive(RestoreOptions $options, ?callable $tickCallback = null): string
    {
        $archive = realpath($options->archive) ?: $options->archive;
        if (!is_file($archive)) {
            throw new RuntimeException("Archive not found: {$archive}");
        }

        $tempDir = $options->tempDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-restore';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0777, true)) {
            throw new RuntimeException("Unable to create temp directory: {$tempDir}");
        }

        // Derive password from filename (DB_PASSWORD + randomString) or use explicit password
        $password = $options->encryptionPassword ?? $this->derivePasswordFromFilename($archive, $options->database->password);

        $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));

        // Handle .gpg encrypted files (GPG encrypted - VLSM-compatible)
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
        // Extract random string from filename (VLSM-compatible format)
        // Format: label-YYYYMMDD-HHMMSS-RANDOMSTRING.sql.zst.gpg or label-YYYYMMDD-HHMMSS-RANDOMSTRING-note.sql.zst.gpg
        $basename = basename($archivePath);

        // Match: name-YYYYMMDD-HHMMSS-RANDOMSTRING (32 alphanumeric chars)
        if (preg_match('/-(\d{8})-(\d{6})-([A-Za-z0-9]{32})/', $basename, $matches)) {
            $randomString = $matches[3];
            return ($dbPassword ?? '') . $randomString;
        }

        return null;
    }

    private function decryptFile(string $encryptedPath, string $password, string $tempDir, ?callable $tickCallback = null): string
    {
        // Remove .gpg extension to get the original filename
        $decryptedPath = $tempDir . DIRECTORY_SEPARATOR . basename($encryptedPath, '.gpg');

        // Use GPG for decryption (VLSM-compatible)
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
        // Remove .enc extension to get the original filename (legacy openssl format)
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
            'mysql',
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
            'mysql',
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
