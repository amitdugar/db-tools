<?php

declare(strict_types=1);

namespace DbTools\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

final class CollationService implements CollationServiceInterface
{
    private const MYSQL8_COLLATION = 'utf8mb4_0900_ai_ci';
    private const MYSQL5_COLLATION = 'utf8mb4_unicode_ci';
    private const CHARSET = 'utf8mb4';

    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function changeCollation(array $options): void
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            throw new RuntimeException('Missing required option: database');
        }

        $collation = $options['collation'] ?? $this->getRecommendedCollation($options);
        $charset = $options['charset'] ?? self::CHARSET;

        $sql = \sprintf(
            'ALTER DATABASE `%s` CHARACTER SET %s COLLATE %s;',
            $database,
            $charset,
            $collation
        );

        $this->runQuery($options, $sql);
        $this->logger?->info('Database collation changed', ['database' => $database, 'collation' => $collation]);
    }

    /**
     * Convert database tables and columns to target collation.
     * Follows VLSM script approach:
     * 1. For each table, check if table collation needs conversion
     * 2. Convert table if needed (this converts most columns too)
     * 3. Check which columns still need conversion
     * 4. Convert remaining columns individually
     *
     * @param array<string, mixed> $options
     * @return array{tables_converted: int, tables_skipped: int, columns_converted: int, columns_skipped: int, columns_failed_verification: int, errors: list<string>}
     */
    public function convert(array $options): array
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            throw new RuntimeException('Missing required option: database');
        }

        $targetCollation = $options['collation'] ?? $this->getRecommendedCollation($options);
        $charset = $options['charset'] ?? self::CHARSET;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $skipColumns = (bool) ($options['skip_columns'] ?? false);
        $specificTable = $options['table'] ?? null;
        $callback = $options['callback'] ?? null;
        $disableFkChecks = (bool) ($options['disable_fk_checks'] ?? true);

        $result = [
            'tables_converted' => 0,
            'tables_skipped' => 0,
            'columns_converted' => 0,
            'columns_skipped' => 0,
            'columns_failed_verification' => 0,
            'errors' => [],
        ];

        $this->logger?->info('Starting collation conversion', [
            'database' => $database,
            'collation' => $targetCollation,
            'dry_run' => $dryRun,
        ]);

        // Get all tables
        $tables = $this->getTables($options);

        $dependencies = [];
        if ($specificTable !== null) {
            $tables = array_filter($tables, fn($t) => $t['table'] === $specificTable);
        } else {
            // Sort tables by FK dependencies (parents before children)
            $dependencies = $this->getForeignKeyDependencies($options);
            $tableNames = array_column($tables, 'table');
            $sortedNames = $this->sortTablesByDependencies($tableNames, $dependencies);

            // Reorder $tables array to match sorted order
            $tablesByName = [];
            foreach ($tables as $t) {
                $tablesByName[$t['table']] = $t;
            }
            $tables = array_map(fn($name) => $tablesByName[$name], $sortedNames);

            if ($callback !== null && $dependencies !== []) {
                $callback('dependencies_detected', '', ['count' => \count($dependencies)]);
            }
        }

        // Check if any tables with FK relationships need conversion
        $tablesNeedingConversion = array_filter($tables, fn($t) => $t['current_collation'] !== $targetCollation);
        $fkTablesNeedConversion = [];
        foreach ($tablesNeedingConversion as $t) {
            $tableName = $t['table'];
            // Check if this table is involved in any FK relationship (as parent or child)
            $isChild = isset($dependencies[$tableName]);
            $isParent = false;
            foreach ($dependencies as $parents) {
                if (\in_array($tableName, $parents, true)) {
                    $isParent = true;
                    break;
                }
            }
            if ($isChild || $isParent) {
                $fkTablesNeedConversion[] = $tableName;
            }
        }

        // If we have FK-related tables that need conversion, warn or prepare to disable FK checks
        if ($fkTablesNeedConversion !== [] && !$dryRun) {
            if ($disableFkChecks) {
                if ($callback !== null) {
                    $callback('fk_checks_disabled', '', ['tables' => $fkTablesNeedConversion]);
                }
            } elseif ($callback !== null) {
                $callback('fk_tables_need_conversion', '', ['tables' => $fkTablesNeedConversion]);
            }
        }

        foreach ($tables as $tableInfo) {
            $tableName = $tableInfo['table'];

            if ($callback !== null) {
                $callback('table_start', $tableName, $tableInfo);
            }

            // Step 1: Check if table collation needs conversion
            $tableNeedsConversion = $tableInfo['current_collation'] !== $targetCollation;

            if (!$tableNeedsConversion) {
                // Table collation is OK, but we still need to check columns
                if ($callback !== null) {
                    $callback('table_skipped', $tableName, $tableInfo);
                }
                $result['tables_skipped']++;
            } else {
                // Step 2: Convert table
                if (!$dryRun) {
                    try {
                        $startTime = microtime(true);
                        // Include FK check disable in same query if flag is set (must be same session)
                        if ($disableFkChecks) {
                            $sql = \sprintf(
                                'SET FOREIGN_KEY_CHECKS=0; ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s; SET FOREIGN_KEY_CHECKS=1;',
                                $tableName,
                                $charset,
                                $targetCollation
                            );
                        } else {
                            $sql = \sprintf(
                                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                                $tableName,
                                $charset,
                                $targetCollation
                            );
                        }
                        $this->runQuery($options, $sql);
                        $duration = round(microtime(true) - $startTime, 2);
                        $result['tables_converted']++;

                        if ($callback !== null) {
                            $callback('table_converted', $tableName, [...$tableInfo, 'duration' => $duration]);
                        }
                    } catch (RuntimeException $e) {
                        // Check if this is an FK incompatibility error (ERROR 3780)
                        $isFkError = str_contains($e->getMessage(), '3780') || str_contains($e->getMessage(), 'foreign key constraint');
                        $result['errors'][] = "Table {$tableName}: " . $e->getMessage();
                        if ($callback !== null) {
                            $callback('table_error', $tableName, ['error' => $e->getMessage(), 'is_fk_error' => $isFkError]);
                        }
                        continue; // Skip column conversion if table conversion failed
                    }
                } else {
                    $result['tables_skipped']++;
                    if ($callback !== null) {
                        $callback('table_dry_run', $tableName, $tableInfo);
                    }
                }
            }

            // Step 3: Check which columns still need conversion (after table conversion)
            if (!$skipColumns) {
                $columns = $this->getColumnsNeedingConversion([
                    ...$options,
                    'table' => $tableName,
                    'collation' => $targetCollation,
                ]);

                if ($columns === []) {
                    if ($callback !== null) {
                        $callback('columns_all_ok', $tableName, []);
                    }
                    continue;
                }

                if ($callback !== null) {
                    $callback('columns_need_conversion', $tableName, ['count' => \count($columns)]);
                }

                // Step 4: Convert remaining columns individually
                foreach ($columns as $columnInfo) {
                    $columnName = $columnInfo['column'];

                    if ($callback !== null) {
                        $indexes = $this->getColumnIndexes($options, $tableName, $columnName);
                        $callback('column_start', $tableName, [...$columnInfo, 'indexes' => $indexes]);
                    }

                    if (!$dryRun) {
                        try {
                            $startTime = microtime(true);
                            $columnDef = $this->buildColumnDefinition($columnInfo, $targetCollation, $charset);
                            $sql = \sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $tableName, $columnDef);
                            $this->runQuery($options, $sql);
                            $duration = round(microtime(true) - $startTime, 2);

                            // Verify the conversion was successful
                            if ($this->verifyColumnConversion($options, $tableName, $columnName, $targetCollation)) {
                                $result['columns_converted']++;
                                if ($callback !== null) {
                                    $callback('column_converted', $tableName, [...$columnInfo, 'duration' => $duration]);
                                }
                            } else {
                                $result['columns_failed_verification']++;
                                $result['errors'][] = "Column {$tableName}.{$columnName}: Conversion succeeded but verification failed";
                                if ($callback !== null) {
                                    $callback('column_verification_failed', $tableName, ['column' => $columnName, 'duration' => $duration]);
                                }
                            }
                        } catch (RuntimeException $e) {
                            $result['errors'][] = "Column {$tableName}.{$columnName}: " . $e->getMessage();
                            if ($callback !== null) {
                                $columnDef = $this->buildColumnDefinition($columnInfo, $targetCollation, $charset);
                                $callback('column_error', $tableName, [
                                    'column' => $columnName,
                                    'error' => $e->getMessage(),
                                    'sql' => "ALTER TABLE `{$tableName}` MODIFY COLUMN {$columnDef}",
                                ]);
                            }
                        }
                    } else {
                        $result['columns_skipped']++;
                        $columnDef = $this->buildColumnDefinition($columnInfo, $targetCollation, $charset);
                        if ($callback !== null) {
                            $callback('column_dry_run', $tableName, [...$columnInfo, 'sql' => "ALTER TABLE `{$tableName}` MODIFY COLUMN {$columnDef}"]);
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get all tables in database with their current collation.
     *
     * @param array<string, mixed> $options
     * @return list<array{table: string, current_collation: string, size_mb: float}>
     */
    public function getTables(array $options): array
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            throw new RuntimeException('Missing required option: database');
        }

        $sql = \sprintf(
            "SELECT
                TABLE_NAME,
                TABLE_COLLATION,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '%s'
            AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME",
            addslashes($database)
        );

        $output = $this->runQuery($options, $sql);
        $tables = [];

        $lines = array_filter(explode("\n", trim($output)));
        array_shift($lines); // Skip header

        foreach ($lines as $line) {
            $parts = preg_split('/\t/', $line);
            if (\count($parts) >= 3) {
                $tables[] = [
                    'table' => $parts[0] ?? '',
                    'current_collation' => $parts[1] ?? '',
                    'size_mb' => (float) ($parts[2] ?? 0),
                ];
            }
        }

        return $tables;
    }

    /**
     * Get tables needing conversion (for display purposes).
     *
     * @param array<string, mixed> $options
     * @return list<array{table: string, current_collation: string, size_mb: float, needs_conversion: bool}>
     */
    public function getTablesNeedingConversion(array $options): array
    {
        $targetCollation = $options['collation'] ?? $this->getRecommendedCollation($options);
        $tables = $this->getTables($options);

        return array_map(fn($t) => [
            ...$t,
            'needs_conversion' => $t['current_collation'] !== $targetCollation,
        ], $tables);
    }

    /**
     * Get columns that need conversion for a specific table.
     *
     * @param array<string, mixed> $options
     * @return list<array{column: string, type: string, current_collation: string, is_nullable: string, default: ?string, extra: string, comment: string}>
     */
    public function getColumnsNeedingConversion(array $options): array
    {
        $database = $options['database'] ?? null;
        $table = $options['table'] ?? null;
        if ($database === null || $table === null) {
            throw new RuntimeException('Missing required options: database, table');
        }

        $targetCollation = $options['collation'] ?? $this->getRecommendedCollation($options);

        $sql = \sprintf(
            "SELECT
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                EXTRA,
                COLLATION_NAME,
                COLUMN_COMMENT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '%s'
            AND TABLE_NAME = '%s'
            AND DATA_TYPE IN ('char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set')
            AND COLLATION_NAME IS NOT NULL
            AND COLLATION_NAME != '%s'
            ORDER BY ORDINAL_POSITION",
            addslashes($database),
            addslashes($table),
            addslashes($targetCollation)
        );

        $output = $this->runQuery($options, $sql);
        $columns = [];

        $lines = array_filter(explode("\n", trim($output)));
        array_shift($lines); // Skip header

        foreach ($lines as $line) {
            $parts = preg_split('/\t/', $line);
            if (\count($parts) >= 6) {
                $columns[] = [
                    'column' => $parts[0] ?? '',
                    'type' => $parts[1] ?? '',
                    'is_nullable' => $parts[2] ?? 'YES',
                    'default' => ($parts[3] ?? 'NULL') !== 'NULL' ? $parts[3] : null,
                    'extra' => $parts[4] ?? '',
                    'current_collation' => $parts[5] ?? '',
                    'comment' => $parts[6] ?? '',
                ];
            }
        }

        return $columns;
    }

    /**
     * Get foreign key relationships for all tables in the database.
     * Returns a map of child table => list of parent tables (tables it references).
     *
     * @param array<string, mixed> $options
     * @return array<string, list<string>>
     */
    public function getForeignKeyDependencies(array $options): array
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            throw new RuntimeException('Missing required option: database');
        }

        $sql = \sprintf(
            "SELECT
                TABLE_NAME as child_table,
                REFERENCED_TABLE_NAME as parent_table
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '%s'
            AND REFERENCED_TABLE_SCHEMA = '%s'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            GROUP BY TABLE_NAME, REFERENCED_TABLE_NAME",
            addslashes($database),
            addslashes($database)
        );

        $output = $this->runQuery($options, $sql);
        $dependencies = [];

        $lines = array_filter(explode("\n", trim($output)));
        array_shift($lines); // Skip header

        foreach ($lines as $line) {
            $parts = preg_split('/\t/', $line);
            if (\count($parts) >= 2) {
                $childTable = $parts[0] ?? '';
                $parentTable = $parts[1] ?? '';
                if ($childTable !== '' && $parentTable !== '') {
                    if (!isset($dependencies[$childTable])) {
                        $dependencies[$childTable] = [];
                    }
                    if (!\in_array($parentTable, $dependencies[$childTable], true)) {
                        $dependencies[$childTable][] = $parentTable;
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Sort tables in dependency order (parents before children).
     * Tables with no FK dependencies come first, then tables in order of their dependencies.
     *
     * @param list<string> $tables List of table names
     * @param array<string, list<string>> $dependencies Map of child => parents
     * @return list<string> Tables sorted so parents come before children
     */
    public function sortTablesByDependencies(array $tables, array $dependencies): array
    {
        // Build reverse lookup: which tables depend on each table
        $dependents = [];
        foreach ($tables as $table) {
            $dependents[$table] = [];
        }
        foreach ($dependencies as $child => $parents) {
            foreach ($parents as $parent) {
                if (isset($dependents[$parent])) {
                    $dependents[$parent][] = $child;
                }
            }
        }

        // Kahn's algorithm for topological sort
        $inDegree = [];
        foreach ($tables as $table) {
            // Count how many parents this table has (that are in our table list)
            $parents = $dependencies[$table] ?? [];
            $inDegree[$table] = \count(array_filter($parents, fn($p) => \in_array($p, $tables, true)));
        }

        // Start with tables that have no dependencies
        $queue = [];
        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $queue[] = $table;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $table = array_shift($queue);
            $sorted[] = $table;

            // Reduce in-degree for all tables that depend on this one
            foreach ($dependents[$table] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // If we didn't get all tables, there's a cycle - add remaining tables at the end
        $remaining = array_diff($tables, $sorted);
        if ($remaining !== []) {
            $this->logger?->warning('Circular FK dependencies detected', ['tables' => array_values($remaining)]);
            $sorted = [...$sorted, ...array_values($remaining)];
        }

        return $sorted;
    }

    /**
     * Get indexes that reference a specific column.
     *
     * @param array<string, mixed> $options
     * @return list<array{index_name: string, non_unique: bool, index_type: string}>
     */
    public function getColumnIndexes(array $options, string $table, string $column): array
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            return [];
        }

        $sql = \sprintf(
            "SELECT DISTINCT
                INDEX_NAME,
                NON_UNIQUE,
                INDEX_TYPE
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = '%s'
            AND TABLE_NAME = '%s'
            AND COLUMN_NAME = '%s'
            AND INDEX_NAME != 'PRIMARY'",
            addslashes($database),
            addslashes($table),
            addslashes($column)
        );

        try {
            $output = $this->runQuery($options, $sql);
        } catch (RuntimeException) {
            return [];
        }

        $indexes = [];
        $lines = array_filter(explode("\n", trim($output)));
        array_shift($lines); // Skip header

        foreach ($lines as $line) {
            $parts = preg_split('/\t/', $line);
            if (\count($parts) >= 3) {
                $indexes[] = [
                    'index_name' => $parts[0] ?? '',
                    'non_unique' => ($parts[1] ?? '1') !== '0',
                    'index_type' => $parts[2] ?? '',
                ];
            }
        }

        return $indexes;
    }

    /**
     * Verify that a column conversion was successful.
     *
     * @param array<string, mixed> $options
     */
    public function verifyColumnConversion(array $options, string $table, string $column, string $targetCollation): bool
    {
        $database = $options['database'] ?? null;
        if ($database === null) {
            return false;
        }

        $sql = \sprintf(
            "SELECT COLLATION_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '%s'
            AND TABLE_NAME = '%s'
            AND COLUMN_NAME = '%s'",
            addslashes($database),
            addslashes($table),
            addslashes($column)
        );

        try {
            $output = $this->runQuery($options, $sql);
        } catch (RuntimeException) {
            return false;
        }

        $lines = array_filter(explode("\n", trim($output)));
        array_shift($lines); // Skip header

        $currentCollation = trim($lines[0] ?? '');

        return $currentCollation === $targetCollation;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function isMysql8OrHigher(array $options): bool
    {
        try {
            $output = $this->runQuery($options, 'SELECT VERSION()');
            $lines = explode("\n", trim($output));
            $version = $lines[1] ?? '';
            return version_compare($version, '8.0.0', '>=');
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getRecommendedCollation(array $options): string
    {
        return $this->isMysql8OrHigher($options) ? self::MYSQL8_COLLATION : self::MYSQL5_COLLATION;
    }

    /**
     * Build complete column definition preserving all properties.
     *
     * @param array{column: string, type: string, is_nullable: string, default: ?string, extra: string, comment: string} $column
     */
    private function buildColumnDefinition(array $column, string $collation, string $charset): string
    {
        $definition = \sprintf('`%s` %s CHARACTER SET %s COLLATE %s', $column['column'], $column['type'], $charset, $collation);

        // Handle NULL/NOT NULL
        if ($column['is_nullable'] === 'NO') {
            $definition .= ' NOT NULL';
        } else {
            $definition .= ' NULL';
        }

        // Handle DEFAULT values
        if ($column['default'] !== null) {
            $defaultValue = $column['default'];

            // Special function defaults that don't need quotes
            $functionDefaults = [
                'CURRENT_TIMESTAMP',
                'current_timestamp()',
                'now()',
                'CURRENT_TIMESTAMP()',
                'NULL',
                'CURRENT_DATE',
                'CURRENT_TIME',
                'LOCALTIME',
                'LOCALTIMESTAMP',
            ];

            if (\in_array(strtoupper($defaultValue), array_map('strtoupper', $functionDefaults), true)) {
                $definition .= " DEFAULT {$defaultValue}";
            } else {
                $escapedDefault = str_replace("'", "''", $defaultValue);
                $definition .= " DEFAULT '{$escapedDefault}'";
            }
        }

        // Handle EXTRA attributes (AUTO_INCREMENT, ON UPDATE, etc.)
        if ($column['extra'] !== '') {
            $definition .= ' ' . $column['extra'];
        }

        // Handle COMMENT
        if ($column['comment'] !== '') {
            $escapedComment = str_replace("'", "''", $column['comment']);
            $definition .= " COMMENT '{$escapedComment}'";
        }

        return $definition;
    }

    /**
     * Run a MySQL query.
     *
     * @param array<string, mixed> $options
     */
    private function runQuery(array $options, string $sql): string
    {
        $host = (string) ($options['host'] ?? 'localhost');
        $port = isset($options['port']) ? (int) $options['port'] : null;
        $user = isset($options['user']) ? (string) $options['user'] : null;
        $password = isset($options['password']) ? (string) $options['password'] : null;
        $database = isset($options['database']) ? (string) $options['database'] : null;

        $cmd = ['mysql', '--host=' . $host, '--batch'];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }
        if ($database !== null) {
            $cmd[] = $database;
        }

        $cmd[] = '-e';
        $cmd[] = $sql;

        $env = $password !== null ? ['MYSQL_PWD' => $password] : [];

        return $this->runner->runWithInput($cmd, $env, '')->getOutput();
    }
}
