<?php

declare(strict_types=1);

namespace DbTools\Config;

final class RestoreOptions
{
    public function __construct(
        public readonly DatabaseConfig $database,
        public readonly string $archive,
        public readonly bool $force = false,
        public readonly bool $skipSafetyBackup = false,
        public readonly ?string $encryptionPassword = null,
        public readonly ?string $tempDir = null,
        public readonly ?string $outputDir = null,
    ) {
    }
}
