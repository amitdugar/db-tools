<?php

declare(strict_types=1);

namespace DbTools\Service;

use ArchiveUtil\ArchiveUtility;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ImportService implements ImportServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function import(array $options, ?callable $tickCallback = null): void
    {
        foreach (['database', 'file'] as $required) {
            if (!isset($options[$required])) {
                throw new RuntimeException("Missing required option: {$required}");
            }
        }

        $file = (string) $options['file'];
        if (!is_file($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }

        $host = (string) ($options['host'] ?? 'localhost');
        $database = (string) $options['database'];
        $user = isset($options['user']) ? (string) $options['user'] : null;
        $password = isset($options['password']) ? (string) $options['password'] : null;
        $port = isset($options['port']) ? (int) $options['port'] : null;
        $encryptionPassword = isset($options['encryption_password']) ? (string) $options['encryption_password'] : null;

        $this->logger?->info('Importing SQL file', ['file' => $file, 'database' => $database]);

        // Get SQL content (handles decompression and decryption)
        $sqlPath = $this->extractFile($file, $encryptionPassword, $password, $tickCallback);
        $needsCleanup = $sqlPath !== $file;

        try {
            $this->importSql($host, $port, $user, $password, $database, $sqlPath, $tickCallback);
        } finally {
            // Clean up temp file if we extracted
            if ($needsCleanup && is_file($sqlPath)) {
                @unlink($sqlPath);
            }
        }
    }

    /**
     * Check if a file is encrypted (.gpg extension).
     */
    public function isEncrypted(string $file): bool
    {
        return str_ends_with(strtolower($file), '.gpg');
    }

    /**
     * Try to decrypt a file with the given password.
     * Returns true on success, false on failure.
     */
    public function tryDecrypt(string $file, string $password, string $tempDir): ?string
    {
        $decryptedPath = $tempDir . DIRECTORY_SEPARATOR . basename($file, '.gpg');

        $cmd = [
            'gpg', '--batch', '--yes', '--quiet',
            '--passphrase', $password,
            '--decrypt',
            '--output', $decryptedPath,
            $file,
        ];

        try {
            $this->runner->run($cmd);
            return $decryptedPath;
        } catch (RuntimeException) {
            // Decryption failed
            if (is_file($decryptedPath)) {
                @unlink($decryptedPath);
            }
            return null;
        }
    }

    private function extractFile(string $file, ?string $encryptionPassword, ?string $dbPassword, ?callable $tickCallback = null): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-import';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0777, true)) {
            throw new RuntimeException("Unable to create temp directory: {$tempDir}");
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // Handle .gpg encrypted files
        if ($ext === 'gpg') {
            $password = $encryptionPassword ?? $this->derivePasswordFromFilename($file, $dbPassword);
            if ($password === null) {
                throw new RuntimeException('Archive is encrypted; provide --encryption-password or database password');
            }
            $file = $this->decryptFile($file, $password, $tempDir, $tickCallback);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        }

        // Handle password-protected ZIP
        if ($ext === 'zip' && ArchiveUtility::isPasswordProtected($file)) {
            $password = $encryptionPassword ?? $dbPassword;
            if ($password === null) {
                throw new RuntimeException('Archive is password protected; provide --encryption-password');
            }
            return ArchiveUtility::extractPasswordProtectedZip($file, $password, $tempDir);
        }

        // Handle compressed files (.gz, .zst, .zip)
        if (\in_array($ext, ['gz', 'zst', 'zip'], true)) {
            return ArchiveUtility::decompressToFile($file, $tempDir, $tickCallback);
        }

        // Plain SQL - return as-is
        return $file;
    }

    private function derivePasswordFromFilename(string $archivePath, ?string $dbPassword): ?string
    {
        // Extract random string from filename (VLSM-compatible format)
        // Format: label-YYYYMMDD-HHMMSS-RANDOMSTRING.sql.zst.gpg
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

    private function importSql(string $host, ?int $port, ?string $user, ?string $password, string $database, string $sqlPath, ?callable $tickCallback = null): void
    {
        if (!is_file($sqlPath)) {
            throw new RuntimeException("SQL file not found: {$sqlPath}");
        }

        $cmd = ['mysql', '--host=' . $host];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }

        $cmd[] = $database;

        $env = $password !== null ? ['MYSQL_PWD' => $password] : [];

        $this->runner->runWithFileInput($cmd, $env, $sqlPath, $tickCallback);
    }
}
