<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ProcessRunner implements ProcessRunnerInterface
{
    /** Per-stage limit on retained stderr; draining continues past it, only retention stops. */
    private const STDERR_CAP = 262144;

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
        $lastIndex = count($commands) - 1;

        /** @var list<resource> $processes */
        $processes = [];
        /** @var list<array{0:int,1:resource}> $readable Pipes this side owns and must drain, tagged by stage. */
        $readable = [];
        /** @var array<int,string> $stderr Stage index => stderr collected so far. */
        $stderr = array_fill(0, count($commands), '');
        /** @var array<int,int> $exitCodes Stage index => exit code, captured the moment it exits. */
        $exitCodes = [];
        /** @var array<int,true> $closed Stage indexes whose handle proc_close already took. */
        $closed = [];

        $prevReadPipe = null;

        try {
            foreach ($commands as $i => $command) {
                $isLast = ($i === $lastIndex);

                $descriptors = [
                    0 => $prevReadPipe ?? ['pipe', 'r'], // stdin
                    1 => ['pipe', 'w'],                  // stdout
                    2 => ['pipe', 'w'],                  // stderr
                ];

                $cmdPipes = [];
                $process = proc_open($command, $descriptors, $cmdPipes, null, $fullEnv);
                if (!is_resource($process)) {
                    throw new RuntimeException('Failed to start stage ' . $i . ': ' . self::describe($command));
                }

                $processes[] = $process;

                // proc_open duplicated the upstream read end into this child, so
                // this side's copy has to go. While it stays open the kernel
                // still counts a reader on that pipe, so an upstream stage
                // writing into a pipe whose real consumer has already died never
                // gets EPIPE — it fills the ~64KB buffer and blocks forever,
                // and the dead stage's error is never reported. A restore that
                // hangs silently is far worse than one that fails.
                if ($prevReadPipe !== null) {
                    fclose($prevReadPipe);
                    $prevReadPipe = null;
                }

                // Nothing feeds the first stage.
                if ($i === 0 && isset($cmdPipes[0]) && is_resource($cmdPipes[0])) {
                    fclose($cmdPipes[0]);
                }

                stream_set_blocking($cmdPipes[2], false);
                $readable[] = [$i, $cmdPipes[2]];

                if ($isLast) {
                    // Only the last stage's stdout belongs to this side. Every
                    // earlier one is the next stage's input; reading it here
                    // would steal that stage's data. Nothing consumes the last
                    // stage's output, but it still has to be drained or a
                    // talkative final stage fills the buffer and stalls — hence
                    // the -1 tag, which drains without retaining.
                    stream_set_blocking($cmdPipes[1], false);
                    $readable[] = [-1, $cmdPipes[1]];
                } else {
                    $prevReadPipe = $cmdPipes[1];
                }
            }

            // Poll until every stage exits, draining as we go. Draining has to
            // happen inside the loop: a stage that writes more than the pipe
            // buffer holds blocks until someone reads, so collecting output only
            // after exit deadlocks any stage noisy enough to fill it.
            do {
                if ($tickCallback !== null) {
                    $tickCallback();
                }

                $running = false;
                foreach ($processes as $i => $proc) {
                    if (isset($exitCodes[$i])) {
                        continue;
                    }

                    // proc_get_status reports the real code only on the first
                    // call after the process exits, so capture it right here.
                    $status = proc_get_status($proc);
                    if ($status['running']) {
                        $running = true;
                    } else {
                        $exitCodes[$i] = $status['exitcode'];
                    }
                }

                $this->drain($readable, $stderr);

                if ($running) {
                    usleep(100_000);
                }
            } while ($running);

            // Whatever the stages wrote just before exiting is still buffered.
            $this->drain($readable, $stderr);

            foreach ($readable as [, $pipe]) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $readable = [];

            /** @var array<int,int> $failed Stage index => exit code, for every stage that did not exit 0. */
            $failed = [];

            foreach ($processes as $i => $proc) {
                $closeCode = proc_close($proc);
                $closed[$i] = true;

                $exitCode = $exitCodes[$i] ?? -1;
                if ($exitCode === -1) {
                    $exitCode = $closeCode;
                }

                if ($exitCode !== 0) {
                    $failed[$i] = $exitCode;
                }
            }

            if ($failed !== []) {
                throw new RuntimeException($this->explainFailure($commands, $failed, $stderr));
            }
        } catch (\Throwable $e) {
            // Kill any still-running processes. Stages before the failing one
            // have already been closed above; touching those handles again
            // raises a TypeError that @ does not suppress, which would replace
            // the real pipeline error with an unrelated fatal.
            foreach ($readable as [, $pipe]) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if ($prevReadPipe !== null && is_resource($prevReadPipe)) {
                @fclose($prevReadPipe);
            }

            foreach ($processes as $i => $proc) {
                if (isset($closed[$i])) {
                    continue;
                }

                $status = @proc_get_status($proc);
                if ($status && $status['running']) {
                    @proc_terminate($proc, 9);
                }
                @proc_close($proc);
            }

            throw $e;
        }
    }

    /**
     * Read whatever is available without blocking. Anything past the cap is
     * dropped rather than left unread — the point is to keep the pipe moving,
     * and the head of a stage's stderr is where its first real error is.
     *
     * @param list<array{0:int,1:resource}> $readable
     * @param array<int,string>             $stderr
     */
    private function drain(array $readable, array &$stderr): void
    {
        foreach ($readable as [$stage, $pipe]) {
            if (!is_resource($pipe)) {
                continue;
            }

            // Bounded per call so one stage writing continuously cannot starve
            // the other pipes or stall the progress tick; the next pass picks up
            // wherever this one left off.
            for ($chunks = 0; $chunks < 64; $chunks++) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                if ($stage >= 0 && strlen($stderr[$stage]) < self::STDERR_CAP) {
                    $stderr[$stage] .= $chunk;
                }
            }
        }
    }

    /**
     * Turn the set of failed stages into one message that names them.
     *
     * When a stage dies, every stage upstream of it takes SIGPIPE on its next
     * write. Those deaths are collateral, and reporting the first failing stage
     * by index surfaces "Broken pipe" while burying the reason the pipeline
     * actually stopped. So collateral failures are dropped whenever a stage
     * failed on its own terms.
     *
     * @param list<list<string>> $commands
     * @param array<int,int>     $failed   Stage index => exit code
     * @param array<int,string>  $stderr
     */
    private function explainFailure(array $commands, array $failed, array $stderr): string
    {
        $substantive = array_filter(
            $failed,
            fn (int $code, int $stage): bool => !self::isCollateral($stderr[$stage], $code),
            ARRAY_FILTER_USE_BOTH
        );

        $report = $substantive !== [] ? $substantive : $failed;

        $parts = [];
        foreach ($report as $stage => $code) {
            $detail = trim($stderr[$stage]) !== ''
                ? trim($stderr[$stage])
                : 'exit code ' . $code;

            $parts[] = 'stage ' . $stage . ' (' . self::describe($commands[$stage]) . '): ' . $detail;
        }

        return 'Pipeline failed at ' . implode('; also ', $parts);
    }

    /**
     * A stage killed by SIGPIPE, or one whose only complaint is a broken pipe,
     * is reacting to a downstream death rather than reporting its own problem.
     */
    private static function isCollateral(string $stderr, int $exitCode): bool
    {
        // 141 = 128 + SIGPIPE; proc_close reports the signal this way.
        if ($exitCode === 141 || $exitCode === -13) {
            return true;
        }

        $text = trim($stderr);
        if ($text === '') {
            return false;
        }

        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '' && stripos($line, 'broken pipe') === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render a stage for an error message without echoing the encryption
     * passphrase into the console or the logs.
     *
     * @param list<string> $command
     */
    private static function describe(array $command): string
    {
        $parts = [];
        $redactNext = false;

        foreach ($command as $arg) {
            if ($redactNext) {
                $parts[] = '<redacted>';
                $redactNext = false;
                continue;
            }

            $parts[] = $arg;
            $redactNext = ($arg === '--passphrase');
        }

        return implode(' ', $parts);
    }
}
