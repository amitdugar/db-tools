<?php

declare(strict_types=1);

namespace DbTools\Service;

interface PitrRestoreServiceInterface
{
    /**
     * Apply binary logs to reach a point-in-time from a meta file or explicit binlog list.
     *
     * @param array<string,mixed> $options
     */
    public function restore(array $options): void;
}
