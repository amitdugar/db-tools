<?php

declare(strict_types=1);

namespace DbTools\Tests\Helper;

use DbTools\Service\ProcessRunnerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{cmd: list<string>, env: array<string, string>}> */
    public array $invocations = [];

    public ?string $lastImport = null;

    /** @var list<string> Stdout handed to successive runWithInput() calls, FIFO. */
    public array $scriptedOutput = [];

    /** When set, runWithInput() throws with this message instead of succeeding. */
    public ?string $failure = null;

    public function run(array $command, array $env = [], ?callable $tickCallback = null): Process
    {
        $this->invocations[] = ['cmd' => $command, 'env' => $env];

        // BinaryResolver may hand back either the classic or the MariaDB name
        // depending on what is installed, so match on both.
        $first = basename((string) ($command[0] ?? ''));
        if ($first === 'mysqldump' || $first === 'mariadb-dump') {
            $dest = $this->extractOption($command, '--result-file=');
            if ($dest === null) {
                throw new RuntimeException('FakeProcessRunner: result-file not provided');
            }
            @file_put_contents($dest, "-- SQL DUMP\nCREATE TABLE t (id INT);\n");
        } elseif ($first === 'mysqlbinlog' || $first === 'mariadb-binlog') {
            return new FakeProcess('BINLOG_SQL;');
        }

        return new FakeProcess();
    }

    public function runWithInput(array $command, array $env = [], ?string $input = null): Process
    {
        $this->invocations[] = ['cmd' => $command, 'env' => $env];
        $this->lastImport = $input;

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return new FakeProcess(array_shift($this->scriptedOutput) ?? '');
    }

    public function runWithFileInput(array $command, array $env, string $filePath, ?callable $tickCallback = null): Process
    {
        $this->invocations[] = ['cmd' => $command, 'env' => $env];
        $this->lastImport = file_get_contents($filePath) ?: null;
        return new FakeProcess();
    }

    /** @var list<list<list<string>>> */
    public array $pipelines = [];

    public function runPipeline(array $commands, array $env = [], ?callable $tickCallback = null): void
    {
        $this->pipelines[] = $commands;
        $this->invocations[] = ['cmd' => ['pipeline:' . count($commands) . '-stages'], 'env' => $env];
    }

    private function extractOption(array $command, string $prefix): ?string
    {
        foreach ($command as $value) {
            if (str_starts_with((string) $value, $prefix)) {
                return substr((string) $value, strlen($prefix));
            }
        }
        return null;
    }
}
