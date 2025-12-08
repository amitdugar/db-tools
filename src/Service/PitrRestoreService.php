<?php

declare(strict_types=1);

namespace DbTools\Service;

use DbTools\Config\DatabaseConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PitrRestoreService implements PitrRestoreServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function restore(array $options): void
    {
        foreach (['database', 'to'] as $required) {
            if (!isset($options[$required])) {
                throw new RuntimeException("Missing required option: {$required}");
            }
        }

        $db = new DatabaseConfig(
            host: (string) ($options['host'] ?? 'localhost'),
            database: (string) $options['database'],
            user: isset($options['user']) ? (string) $options['user'] : null,
            password: isset($options['password']) ? (string) $options['password'] : null,
            port: isset($options['port']) ? (int) $options['port'] : null,
        );

        $to = (string) $options['to'];
        $metaPath = isset($options['meta']) ? (string) $options['meta'] : null;
        $binlogs = $this->resolveBinlogs($options, $metaPath);

        if ($binlogs === []) {
            throw new RuntimeException('No binlogs found for PITR');
        }

        foreach ($binlogs as $binlog) {
            $cmd = [
                'mysqlbinlog',
                '--stop-datetime=' . $to,
                $binlog,
            ];

            if ($db->database !== '') {
                $cmd[] = '--database=' . $db->database;
            }

            $this->logger?->info('Applying binlog', ['file' => $binlog]);

            $binlogOutput = $this->runner->run($cmd)->getOutput();

            $mysqlCmd = [
                'mysql',
                '--host=' . $db->host,
                '--user=' . ($db->user ?? ''),
                $db->database,
            ];

            if ($db->port) {
                $mysqlCmd[] = '--port=' . $db->port;
            }

            $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
            $this->runner->runWithInput($mysqlCmd, $env, $binlogOutput);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,string>
     */
    private function resolveBinlogs(array $options, ?string $metaPath): array
    {
        if (isset($options['binlogs']) && is_array($options['binlogs'])) {
            return array_values(array_map('strval', $options['binlogs']));
        }

        if ($metaPath) {
            $meta = $this->readMeta($metaPath);
            if (isset($meta['binlogs']) && is_array($meta['binlogs'])) {
                $dir = isset($meta['binlog_dir']) ? (string) $meta['binlog_dir'] : null;
                return array_values(array_map(
                    static fn($file) => $dir ? rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file : (string) $file,
                    $meta['binlogs']
                ));
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function readMeta(string $metaPath): array
    {
        if (!is_file($metaPath)) {
            throw new RuntimeException("Meta file not found: {$metaPath}");
        }

        $data = json_decode((string) file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid meta JSON');
        }

        return $data;
    }
}
