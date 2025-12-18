<?php

declare(strict_types=1);

namespace DbTools\Service;

interface CleanServiceInterface
{
    /**
     * Clean backup directory and optionally purge binlogs.
     *
     * @param array<string,mixed> $options
     */
    public function clean(array $options): void;
}
