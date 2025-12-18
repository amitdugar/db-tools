<?php

declare(strict_types=1);

namespace DbTools\Service;

interface ImportServiceInterface
{
    /**
     * Import a SQL file into a database.
     *
     * @param array<string, mixed> $options
     * @param callable|null $tickCallback Optional callback called every 100ms during import
     */
    public function import(array $options, ?callable $tickCallback = null): void;

    /**
     * Check if a file is encrypted (.gpg extension).
     */
    public function isEncrypted(string $file): bool;

    /**
     * Try to decrypt a file with the given password.
     * Returns the path to decrypted file on success, null on failure.
     */
    public function tryDecrypt(string $file, string $password, string $tempDir): ?string;
}
