<?php

declare(strict_types=1);

namespace DbTools\Service;

interface SizeServiceInterface
{
    /**
     * Get database size information.
     *
     * @param array<string, mixed> $options
     * @return array{database: string, total_size: int, data_size: int, index_size: int, tables: list<array{name: string, rows: int, data_size: int, index_size: int, total_size: int}>}
     */
    public function getSize(array $options): array;
}
