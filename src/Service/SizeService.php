<?php

declare(strict_types=1);

namespace DbTools\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

final class SizeService implements SizeServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{database: string, total_size: int, data_size: int, index_size: int, tables: list<array{name: string, rows: int, data_size: int, index_size: int, total_size: int}>}
     */
    public function getSize(array $options): array
    {
        $database = $options['database'] ?? null;
        if ($database === null || $database === '') {
            throw new RuntimeException('Database name is required');
        }

        $host = $options['host'] ?? 'localhost';
        $port = $options['port'] ?? null;
        $user = $options['user'] ?? null;
        $password = $options['password'] ?? null;

        $this->logger?->info('Getting database size', ['database' => $database]);

        // Query to get table sizes
        $sql = sprintf(
            "SELECT
                TABLE_NAME as table_name,
                TABLE_ROWS as table_rows,
                DATA_LENGTH as data_length,
                INDEX_LENGTH as index_length
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '%s'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC",
            addslashes($database)
        );

        $result = $this->runMysqlQuery($host, $port, $user, $password, $database, $sql);

        $tables = [];
        $totalDataSize = 0;
        $totalIndexSize = 0;

        $lines = array_filter(explode("\n", trim($result)));
        // Skip header line
        array_shift($lines);

        foreach ($lines as $line) {
            $parts = preg_split('/\t/', $line);
            if (\count($parts) >= 4) {
                $dataSize = (int) ($parts[2] ?? 0);
                $indexSize = (int) ($parts[3] ?? 0);

                $tables[] = [
                    'name' => $parts[0] ?? '',
                    'rows' => (int) ($parts[1] ?? 0),
                    'data_size' => $dataSize,
                    'index_size' => $indexSize,
                    'total_size' => $dataSize + $indexSize,
                ];

                $totalDataSize += $dataSize;
                $totalIndexSize += $indexSize;
            }
        }

        return [
            'database' => $database,
            'total_size' => $totalDataSize + $totalIndexSize,
            'data_size' => $totalDataSize,
            'index_size' => $totalIndexSize,
            'tables' => $tables,
        ];
    }

    private function runMysqlQuery(string $host, ?int $port, ?string $user, ?string $password, string $database, string $sql): string
    {
        $cmd = ['mysql', '--host=' . $host, '--batch'];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }

        $cmd[] = $database;
        $cmd[] = '-e';
        $cmd[] = $sql;

        $env = $password !== null ? ['MYSQL_PWD' => $password] : [];

        return $this->runner->runWithInput($cmd, $env, '')->getOutput();
    }
}
