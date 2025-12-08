<?php

declare(strict_types=1);

namespace DbTools\Service;

use ArchiveUtil\ArchiveUtility;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class VerifyService implements VerifyServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function verify(array $options): void
    {
        if (!isset($options['target'])) {
            throw new RuntimeException('Missing required option: target');
        }

        $target = (string) $options['target'];
        $password = isset($options['password']) ? (string) $options['password'] : null;
        $dbPassword = isset($options['db_password']) ? (string) $options['db_password'] : null;
        $this->logger?->info('Verifying target', ['target' => $target]);

        if (is_dir($target)) {
            $this->verifyDirectory($target);
            return;
        }

        if (!is_file($target)) {
            throw new RuntimeException("Target not found: {$target}");
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        // Handle .gpg encrypted files (VLSM-compatible)
        if ($ext === 'gpg') {
            $derivedPassword = $password ?? $this->derivePasswordFromFilename($target, $dbPassword);
            if ($derivedPassword === null) {
                throw new RuntimeException('Archive is encrypted; pass --password or --db-password to verify');
            }
            $this->verifyGpgFile($target, $derivedPassword);
            return;
        }

        // Handle .enc encrypted files (legacy openssl)
        if ($ext === 'enc') {
            if ($password === null) {
                throw new RuntimeException('Archive is encrypted; pass --password to verify');
            }
            $this->verifyEncryptedFile($target, $password);
            return;
        }

        if ($ext === 'zip' && ArchiveUtility::isPasswordProtected($target) && $password === null) {
            throw new RuntimeException('Archive is password protected; pass --password to verify');
        }

        if ($ext === 'zip' && ArchiveUtility::isPasswordProtected($target) && $password !== null) {
            $this->verifyPasswordProtectedZip($target, $password);
            return;
        }

        $ok = ArchiveUtility::validateArchive($target);
        if (!$ok) {
            throw new RuntimeException('Archive validation failed: ' . $target);
        }
    }

    private function derivePasswordFromFilename(string $archivePath, ?string $dbPassword): ?string
    {
        $basename = basename($archivePath);

        // Match: name-YYYYMMDD-HHMMSS-RANDOMSTRING (32 alphanumeric chars)
        if (preg_match('/-(\d{8})-(\d{6})-([A-Za-z0-9]{32})/', $basename, $matches)) {
            $randomString = $matches[3];
            return ($dbPassword ?? '') . $randomString;
        }

        return null;
    }

    private function verifyGpgFile(string $target, string $password): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-verify-' . bin2hex(random_bytes(4));
        @mkdir($temp, 0777, true);

        $decryptedPath = $temp . DIRECTORY_SEPARATOR . basename($target, '.gpg');

        try {
            $cmd = [
                'gpg', '--batch', '--yes',
                '--passphrase', $password,
                '--decrypt',
                '--output', $decryptedPath,
                $target,
            ];
            $this->runner->run($cmd);

            // Verify the decrypted archive
            $ok = ArchiveUtility::validateArchive($decryptedPath);
            if (!$ok) {
                throw new RuntimeException('Archive validation failed after decryption: ' . $target);
            }
        } finally {
            $this->deleteDir($temp);
        }
    }

    private function verifyEncryptedFile(string $target, string $password): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-verify-' . bin2hex(random_bytes(4));
        @mkdir($temp, 0777, true);

        $decryptedPath = $temp . DIRECTORY_SEPARATOR . basename($target, '.enc');

        try {
            $cmd = [
                'openssl', 'enc', '-aes-256-cbc', '-d', '-pbkdf2',
                '-in', $target,
                '-out', $decryptedPath,
                '-pass', 'pass:' . $password,
            ];
            $this->runner->run($cmd);

            // Verify the decrypted archive
            $ok = ArchiveUtility::validateArchive($decryptedPath);
            if (!$ok) {
                throw new RuntimeException('Archive validation failed after decryption: ' . $target);
            }
        } finally {
            $this->deleteDir($temp);
        }
    }

    private function verifyDirectory(string $dir): void
    {
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [];
        if ($files === []) {
            throw new RuntimeException("Directory empty: {$dir}");
        }

        foreach ($files as $file) {
            if (is_file($file) && !ArchiveUtility::validateArchive($file)) {
                throw new RuntimeException("Archive validation failed: {$file}");
            }
        }
    }

    private function verifyPasswordProtectedZip(string $target, string $password): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-verify-' . bin2hex(random_bytes(4));
        @mkdir($temp, 0777, true);
        try {
            ArchiveUtility::extractPasswordProtectedZip($target, $password, $temp);
        } finally {
            $this->deleteDir($temp);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
