<?php

declare(strict_types=1);

namespace DbTools\Config;

final class Profile
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $database,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly ?string $outputDir,
        public readonly ?int $retention,
        public readonly ?string $encryptionPassword,
        public readonly ?string $compression,
        public readonly ?string $label,
    ) {
    }

    /**
     * Validate the profile and return any warnings/errors.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];

        if ($this->database === null || $this->database === '') {
            $warnings[] = 'database is not set (will need CLI arg or DBTOOLS_DATABASE env)';
        }

        if ($this->user === null || $this->user === '') {
            $warnings[] = 'user is not set (will need CLI arg or DBTOOLS_USER env)';
        }

        if ($this->port !== null && ($this->port < 1 || $this->port > 65535)) {
            $errors[] = "invalid port: {$this->port}";
        }

        if ($this->retention !== null && $this->retention < 1) {
            $errors[] = "retention must be at least 1, got: {$this->retention}";
        }

        $validCompressions = ['zstd', 'pigz', 'gzip', 'zip', 'auto'];
        if ($this->compression !== null && !\in_array($this->compression, $validCompressions, true)) {
            $errors[] = "invalid compression: {$this->compression} (valid: " . implode(', ', $validCompressions) . ')';
        }

        // Validate output directory
        if ($this->outputDir !== null && $this->outputDir !== '') {
            if (!is_dir($this->outputDir)) {
                $warnings[] = "output_dir does not exist: {$this->outputDir}";
            } elseif (!is_writable($this->outputDir)) {
                $errors[] = "output_dir is not writable: {$this->outputDir}";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Get a summary of the profile for display.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'user' => $this->user,
            'password' => $this->password !== null ? '********' : null,
            'output_dir' => $this->outputDir,
            'retention' => $this->retention,
            'encryption_password' => $this->encryptionPassword !== null ? '********' : null,
            'compression' => $this->compression,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(?string $name, array $data): self
    {
        return new self(
            $name,
            $data['host'] ?? null,
            isset($data['port']) ? (int) $data['port'] : null,
            $data['database'] ?? null,
            $data['user'] ?? null,
            $data['password'] ?? null,
            $data['output_dir'] ?? $data['outputDir'] ?? null,
            isset($data['retention']) ? (int) $data['retention'] : null,
            $data['encryption_password'] ?? $data['encryptionPassword'] ?? $data['zip_password'] ?? $data['zipPassword'] ?? null,
            $data['compression'] ?? null,
            $data['label'] ?? null,
        );
    }
}
