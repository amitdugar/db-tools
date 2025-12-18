<?php

declare(strict_types=1);

namespace DbTools\Service;

interface BinlogServiceInterface
{
    /**
     * Purge old binary logs.
     *
     * @param array<string,mixed> $options
     */
    public function purge(array $options): void;
}
