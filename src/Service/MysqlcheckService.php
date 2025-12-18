<?php

declare(strict_types=1);

namespace DbTools\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

final class MysqlcheckService implements MysqlcheckServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function check(array $options): array
    {
        return $this->runMysqlcheck($options, []);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function analyze(array $options): array
    {
        return $this->runMysqlcheck($options, ['--analyze']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function optimize(array $options): array
    {
        return $this->runMysqlcheck($options, ['--optimize']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function repair(array $options): array
    {
        return $this->runMysqlcheck($options, ['--repair']);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $extraArgs
     * @return array<string, array{status: string, message: string}>
     */
    private function runMysqlcheck(array $options, array $extraArgs): array
    {
        $database = $options['database'] ?? null;
        if ($database === null || $database === '') {
            throw new RuntimeException('Database name is required');
        }

        $host = $options['host'] ?? 'localhost';
        $port = $options['port'] ?? null;
        $user = $options['user'] ?? null;
        $password = $options['password'] ?? null;

        $operation = $extraArgs[0] ?? '--check';
        $this->logger?->info('Running mysqlcheck', ['database' => $database, 'operation' => $operation]);

        $cmd = ['mysqlcheck', '--host=' . $host];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }

        foreach ($extraArgs as $arg) {
            $cmd[] = $arg;
        }

        $cmd[] = $database;

        $env = $password !== null ? ['MYSQL_PWD' => $password] : [];

        $output = $this->runner->runWithInput($cmd, $env, '')->getOutput();

        return $this->parseOutput($output);
    }

    /**
     * Parse mysqlcheck output into structured results.
     *
     * @return array<string, array{status: string, message: string}>
     */
    private function parseOutput(string $output): array
    {
        $results = [];
        $lines = array_filter(explode("\n", trim($output)));

        foreach ($lines as $line) {
            // Format: database.table  status/message
            if (preg_match('/^(\S+)\s+(.+)$/', $line, $matches)) {
                $table = $matches[1];
                $message = trim($matches[2]);

                // Determine status from message
                $status = 'unknown';
                $lowerMessage = strtolower($message);
                if (str_contains($lowerMessage, 'ok') || str_contains($lowerMessage, 'table is already up to date')) {
                    $status = 'ok';
                } elseif (str_contains($lowerMessage, 'error')) {
                    $status = 'error';
                } elseif (str_contains($lowerMessage, 'warning')) {
                    $status = 'warning';
                } elseif (str_contains($lowerMessage, 'repaired') || str_contains($lowerMessage, 'optimized')) {
                    $status = 'ok';
                }

                $results[$table] = [
                    'status' => $status,
                    'message' => $message,
                ];
            }
        }

        return $results;
    }
}
