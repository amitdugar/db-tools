<?php

declare(strict_types=1);

namespace DbTools\Config;

final class BackupOptions
{
    public function __construct(
        public readonly DatabaseConfig $database,
        public readonly string $outputDir,
        public readonly ?string $note = null,
        public readonly ?int $retention = null,
        public readonly ?string $compressionBackend = null,
        public readonly ?string $encryptionPassword = null,
        public readonly ?string $label = null,
        public readonly bool $encrypt = false,
    ) {
    }
}
