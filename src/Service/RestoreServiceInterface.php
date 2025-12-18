<?php

declare(strict_types=1);

namespace DbTools\Service;

interface RestoreServiceInterface
{
    /**
     * Restore from the given archive path.
     *
     * @param array<string,mixed> $options
     * @param callable|null $tickCallback Optional callback called every 100ms during import
     * @return array{archive_size: int, sql_size: int} File sizes for display
     */
    public function restore(array $options, ?callable $tickCallback = null): array;
}
