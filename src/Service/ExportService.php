<?php

declare(strict_types=1);

namespace DbTools\Service;

use DbTools\Config\DatabaseConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ExportService implements ExportServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function export(array $options): string
    {
        foreach (['database', 'output'] as $required) {
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

        $output = (string) $options['output'];
        $dir = dirname($output);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException("Unable to create output directory: {$dir}");
        }

        $this->logger?->info('Exporting database', ['db' => $db->database, 'output' => $output]);

        $cmd = [
            'mysqldump',
            '--host=' . $db->host,
            '--user=' . ($db->user ?? ''),
            '--single-transaction',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--result-file=' . $output,
            $db->database,
        ];

        if ($db->port) {
            $cmd[] = '--port=' . $db->port;
        }

        $env = $db->password ? ['MYSQL_PWD' => $db->password] : [];
        $this->runner->run($cmd, $env);

        return $output;
    }
}
