<?php

declare(strict_types=1);

namespace DbTools\Service;

use ArchiveUtil\ArchiveUtility;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ImportService implements ImportServiceInterface
{
    private readonly TempFileRegistry $tempRegistry;

    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
        ?TempFileRegistry $tempRegistry = null,
    ) {
        $this->tempRegistry = $tempRegistry ?? new TempFileRegistry();
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

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $derivedPassword = $encryptionPassword ?? $this->derivePasswordFromFilename($file, $password);

        // Try streaming path
        if ($this->canStream($file, $ext, $derivedPassword)) {
            $this->importStreaming($file, $ext, $derivedPassword, $host, $port, $user, $password, $database, $tickCallback);
            return;
        }

        // Fallback: extract to temp file, import, clean up
        $sqlPath = $this->extractFile($file, $encryptionPassword, $password, $tickCallback);
        $needsCleanup = $sqlPath !== $file;

        try {
            $this->importSql($host, $port, $user, $password, $database, $sqlPath, $tickCallback);
        } finally {
            if ($needsCleanup) {
                $this->tempRegistry->cleanup();
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
            if (is_file($decryptedPath)) {
                @unlink($decryptedPath);
            }
            return null;
        }
    }

    private function canStream(string $file, string $ext, ?string $password): bool
    {
        if ($ext === 'gpg' && $password !== null) {
            $inner = strtolower(pathinfo(pathinfo($file, PATHINFO_FILENAME), PATHINFO_EXTENSION));
            return in_array($inner, ['zst', 'gz'], true);
        }

        return in_array($ext, ['zst', 'gz'], true);
    }

    /**
     * Stream: [gpg -d |] decompressor | mysql
     */
    private function importStreaming(
        string $file,
        string $ext,
        ?string $encryptionPassword,
        string $host,
        ?int $port,
        ?string $user,
        ?string $dbPassword,
        string $database,
        ?callable $tickCallback,
    ): void {
        $pipeline = [];

        if ($ext === 'gpg') {
            $pipeline[] = [
                'gpg', '--batch', '--yes',
                '--passphrase', $encryptionPassword,
                '--decrypt', $file,
            ];
            $innerExt = strtolower(pathinfo(pathinfo($file, PATHINFO_FILENAME), PATHINFO_EXTENSION));
        } else {
            $innerExt = $ext;
        }

        $pipeline[] = $this->buildDecompressCommand($innerExt, $ext === 'gpg' ? null : $file);
        $pipeline[] = $this->buildMysqlCommand($host, $port, $user, $database);

        $env = $dbPassword !== null ? ['MYSQL_PWD' => $dbPassword] : [];
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
    private function buildMysqlCommand(string $host, ?int $port, ?string $user, string $database): array
    {
        $cmd = ['mysql', '--host=' . $host];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }

        $cmd[] = $database;

        return $cmd;
    }

    private function extractFile(string $file, ?string $encryptionPassword, ?string $dbPassword, ?callable $tickCallback = null): string
    {
        $tempBase = TempFileRegistry::resolveTempBase();
        $tempDir = $this->tempRegistry->createTempDir($tempBase, 'dbtools-import-');

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
