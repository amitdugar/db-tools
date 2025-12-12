<?php

declare(strict_types=1);

namespace DbTools\Service;

interface BackupServiceInterface
{
    /**
     * Run a backup and return the path to the archive created.
     *
     * @param array<string,mixed> $options
     * @param callable|null $tickCallback Optional callback called every 100ms during backup
     */
    public function backup(array $options, ?callable $tickCallback = null): string;
}
