<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, array $env = [], ?callable $tickCallback = null): Process
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(null);

        if ($tickCallback !== null) {
            // Start async and poll with callback
            $process->start();
            while ($process->isRunning()) {
                $tickCallback();
                usleep(100000); // 100ms
            }
        } else {
            $process->run();
        }

        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'Command failed: ' . implode(' ', $command));
        }

        return $process;
    }

    public function runWithInput(array $command, array $env = [], ?string $input = null): Process
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(null);
        $process->setInput($input ?? '');
        $process->run();

        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'Command failed: ' . implode(' ', $command));
        }

        return $process;
    }

    public function runWithFileInput(array $command, array $env, string $filePath, ?callable $tickCallback = null): Process
    {
        if ($tickCallback !== null) {
            // For large files with callback, use shell redirection to avoid pipe blocking
            $escapedPath = escapeshellarg($filePath);
            $shellCommand = implode(' ', array_map('escapeshellarg', $command)) . ' < ' . $escapedPath;

            $process = Process::fromShellCommandline($shellCommand, null, $env);
            $process->setTimeout(null);
            $process->start();

            while ($process->isRunning()) {
                $tickCallback();
                usleep(100000); // 100ms
            }

            if (!$process->isSuccessful()) {
                $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                throw new RuntimeException($message !== '' ? $message : 'Command failed: ' . implode(' ', $command));
            }

            return $process;
        }

        // Without callback, use standard file handle input
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open file: {$filePath}");
        }

        try {
            $process = new Process($command, null, $env);
            $process->setTimeout(null);
            $process->setInput($handle);
            $process->run();

            if (!$process->isSuccessful()) {
                $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                throw new RuntimeException($message !== '' ? $message : 'Command failed: ' . implode(' ', $command));
            }

            return $process;
        } finally {
            fclose($handle);
        }
    }

    public function runPipeline(array $commands, array $env = [], ?callable $tickCallback = null): void
    {
        if ($commands === []) {
            return;
        }

        if (count($commands) === 1) {
            $this->run($commands[0], $env, $tickCallback);
            return;
        }

        $fullEnv = $env + getenv();

        /** @var list<resource> $processes */
        $processes = [];
        /** @var list<array{0:resource,1:resource,2:resource}> $pipes */
        $pipes = [];

        try {
            $prevReadPipe = null;

            foreach ($commands as $i => $command) {
                $isLast = ($i === count($commands) - 1);

                $descriptors = [
                    0 => $prevReadPipe !== null ? $prevReadPipe : ['pipe', 'r'], // stdin
                    1 => $isLast ? ['pipe', 'w'] : ['pipe', 'w'],               // stdout
                    2 => ['pipe', 'w'],                                          // stderr
                ];

                $cmdPipes = [];
                $process = proc_open($command, $descriptors, $cmdPipes, null, $fullEnv);
                if (!is_resource($process)) {
                    throw new RuntimeException('Failed to start process: ' . implode(' ', $command));
                }

                // Close the stdin pipe we inherited from the previous process
                // (proc_open took ownership of prevReadPipe as descriptor 0)
                if ($prevReadPipe !== null) {
                    // Already handed off to proc_open as FD 0, don't close separately
                    $prevReadPipe = null;
                }

                // For the first process, close its stdin immediately (nothing to feed)
                if ($i === 0 && isset($cmdPipes[0]) && is_resource($cmdPipes[0])) {
                    fclose($cmdPipes[0]);
                }

                $processes[] = $process;
                $pipes[] = $cmdPipes;

                // Set up the pipe for the next process
                if (!$isLast) {
                    $prevReadPipe = $cmdPipes[1]; // next process reads from this stdout
                }
            }

            // Poll until all processes finish
            $running = true;
            while ($running) {
                if ($tickCallback !== null) {
                    $tickCallback();
                }
                usleep(100_000);

                $running = false;
                foreach ($processes as $proc) {
                    $status = proc_get_status($proc);
                    if ($status['running']) {
                        $running = true;
                    }
                }
            }

            // Check exit codes and collect stderr
            foreach ($processes as $i => $proc) {
                // Close stdout pipe if still open (last process)
                if (isset($pipes[$i][1]) && is_resource($pipes[$i][1])) {
                    fclose($pipes[$i][1]);
                }

                $stderr = '';
                if (isset($pipes[$i][2]) && is_resource($pipes[$i][2])) {
                    $stderr = stream_get_contents($pipes[$i][2]) ?: '';
                    fclose($pipes[$i][2]);
                }

                $status = proc_get_status($proc);
                $exitCode = $status['exitcode'];

                // proc_get_status only returns the real exit code on the first call
                // after the process exits. If it returns -1, use proc_close.
                if ($exitCode === -1) {
                    $exitCode = proc_close($proc);
                } else {
                    proc_close($proc);
                }

                if ($exitCode !== 0) {
                    $message = trim($stderr) !== ''
                        ? trim($stderr)
                        : 'Pipeline stage ' . $i . ' failed: ' . implode(' ', $commands[$i]);
                    throw new RuntimeException($message);
                }
            }
        } catch (\Throwable $e) {
            // Kill any still-running processes
            foreach ($processes as $i => $proc) {
                $status = @proc_get_status($proc);
                if ($status && $status['running']) {
                    @proc_terminate($proc, 9);
                }
                // Close any open pipes
                foreach (($pipes[$i] ?? []) as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
                @proc_close($proc);
            }
            if ($prevReadPipe !== null && is_resource($prevReadPipe)) {
                @fclose($prevReadPipe);
            }
            throw $e;
        }
    }
}
