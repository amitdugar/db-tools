<?php

declare(strict_types=1);

namespace DbTools\Service;

interface PitrInfoServiceInterface
{
    /**
     * Show point-in-time recovery details for a backup.
     *
     * @param array<string,mixed> $options
     */
    public function info(array $options): array;
}
