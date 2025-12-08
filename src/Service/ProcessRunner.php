<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, array $env = []): Process
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(null);
        $process->run();

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

    public function runWithFileInput(array $command, array $env, string $filePath): Process
    {
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
}
