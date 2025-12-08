<?php

declare(strict_types=1);

namespace DbTools\Service;

use DbTools\Config\DatabaseConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class BinlogService implements BinlogServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function purge(array $options): void
    {
        foreach (['database'] as $required) {
            if (!isset($options[$required])) {
                throw new RuntimeException("Missing required option: {$required}");
            }
        }

        $days = isset($options['days']) ? (int) $options['days'] : 7;
        $db = new DatabaseConfig(
            host: (string) ($options['host'] ?? 'localhost'),
            database: (string) $options['database'],
            user: isset($options['user']) ? (string) $options['user'] : null,
            password: isset($options['password']) ? (string) $options['password'] : null,
            port: isset($options['port']) ? (int) $options['port'] : null,
        );

        $sql = sprintf('PURGE BINARY LOGS BEFORE DATE(NOW() - INTERVAL %d DAY);', $days);
        $cmd = [
            'mysql',
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            '-e',
            $sql,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->logger?->info('Purging binlogs', ['days' => $days]);
        $this->runner->run($cmd, $env);
    }
}
