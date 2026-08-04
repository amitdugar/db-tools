<?php

declare(strict_types=1);

namespace DbTools\Service;

interface CollationServiceInterface
{
    /**
     * Change database collation (database level only).
     *
     * @param array<string, mixed> $options
     */
    public function changeCollation(array $options): void;

    /**
     * Convert database tables and columns to target collation.
     * This is a comprehensive conversion that handles tables and individual columns.
     *
     * @param array<string, mixed> $options
     * @return array{
     *     tables_converted: int,
     *     tables_skipped: int,
     *     columns_converted: int,
     *     columns_skipped: int,
     *     errors: list<string>
     * }
     */
    public function convert(array $options): array;

    /**
     * Get tables that need collation conversion.
     *
     * @param array<string, mixed> $options
     * @return list<array{table: string, current_collation: string, needs_conversion: bool}>
     */
    public function getTablesNeedingConversion(array $options): array;

    /**
     * Get columns that need collation conversion for a specific table.
     *
     * @param array<string, mixed> $options
     * @return list<array{column: string, type: string, current_collation: string}>
     */
    public function getColumnsNeedingConversion(array $options): array;

    /**
     * Detect if MySQL 8+ is being used. Correctly reports false for MariaDB,
     * whose version numbers exceed 8 but which supports none of the MySQL 8
     * collations before 11.4.
     *
     * @param array<string, mixed> $options
     *
     * @deprecated Use CollationService::serverVersion() for the full flavour
     *             and version. Not added to this interface so that third-party
     *             implementations keep working.
     */
    public function isMysql8OrHigher(array $options): bool;

    /**
     * Get the recommended collation for the detected server flavour and version.
     *
     * @param array<string, mixed> $options
     */
    public function getRecommendedCollation(array $options): string;
}
