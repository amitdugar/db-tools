<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullImportService implements ImportServiceInterface
{
    public function import(array $options, ?callable $tickCallback = null): void
    {
        throw new LogicException('ImportServiceInterface is not configured. Provide your own implementation.');
    }

    public function isEncrypted(string $file): bool
    {
        return false;
    }

    public function tryDecrypt(string $file, string $password, string $tempDir): ?string
    {
        return null;
    }
}
