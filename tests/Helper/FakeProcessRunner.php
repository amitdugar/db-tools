<?php

declare(strict_types=1);

namespace DbTools\Tests\Helper;

use DbTools\Service\ProcessRunnerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var array<int,array{cmd:array,env:array}> */
    public array $invocations = [];

    public ?string $lastImport = null;

    public function run(array $command, array $env = [], ?callable $tickCallback = null): Process
    {
        $this->invocations[] = ['cmd' => $command, 'env' => $env];

        $first = $command[0] ?? '';
        if ($first === 'mysqldump') {
            $dest = $this->extractOption($command, '--result-file=');
            if ($dest === null) {
                throw new RuntimeException('FakeProcessRunner: result-file not provided');
            }
            @file_put_contents($dest, "-- SQL DUMP\nCREATE TABLE t (id INT);\n");
        } elseif ($first === 'mysqlbinlog') {
            return new FakeProcess('BINLOG_SQL;');
        }

        return new FakeProcess();
    }

    public function runWithInput(array $command, array $env = [], ?string $input = null): Process
    {
        $this->invocations[] = ['cmd' => $command, 'env' => $env];
        $this->lastImport = $input;
        return new FakeProcess();
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
