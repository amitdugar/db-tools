<?php

declare(strict_types=1);

namespace DbTools\Service;

interface RestoreServiceInterface
{
    /**
     * Restore from the given archive path.
     *
     * @param array<string,mixed> $options
     */
    public function restore(array $options): void;
}
