<?php

declare(strict_types=1);

namespace DbTools\Service;

use Symfony\Component\Process\Process;

interface ProcessRunnerInterface
{
    /**
     * Run a process and throw on failure.
     *
     * @param list<string> $command
     * @param array<string,string> $env
     * @param callable|null $tickCallback Optional callback called every 100ms while running
     */
    public function run(array $command, array $env = [], ?callable $tickCallback = null): Process;

    /**
     * Run a process with input piping (used for mysqlbinlog streaming).
     *
     * @param list<string> $command
     * @param array<string,string> $env
     * @param string|null $input
     */
    public function runWithInput(array $command, array $env = [], ?string $input = null): Process;

    /**
     * Run a process with file input streaming (memory-efficient for large files).
     *
     * @param list<string> $command
     * @param array<string,string> $env
     * @param string $filePath Path to file to stream as input
     * @param callable|null $tickCallback Optional callback called every 100ms while running
     */
    public function runWithFileInput(array $command, array $env, string $filePath, ?callable $tickCallback = null): Process;
}
