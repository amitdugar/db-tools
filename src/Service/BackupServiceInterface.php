<?php

declare(strict_types=1);

namespace DbTools\Service;

interface BackupServiceInterface
{
    /**
     * Run a backup and return the path to the archive created.
     *
     * @param array<string,mixed> $options
     */
    public function backup(array $options): string;
}
