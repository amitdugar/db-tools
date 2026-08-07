<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * runPipeline backs the streaming restore path, so a failure here is what a
 * user sees when a restore goes wrong. It must surface the stage's own error.
 */
final class ProcessRunnerPipelineTest extends TestCase
{
    private function requireBinaries(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!is_executable($path)) {
                self::markTestSkipped("Requires {$path}");
            }
        }
    }

    public function testSucceedingPipelineRunsCleanly(): void
    {
        $this->requireBinaries('/bin/echo', '/bin/cat');

        (new ProcessRunner())->runPipeline([
            ['/bin/echo', 'hello'],
            ['/bin/cat'],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function testFailingLastStageReportsRuntimeExceptionNotTypeError(): void
    {
        $this->requireBinaries('/bin/echo', '/usr/bin/false');

        $runner = new ProcessRunner();

        try {
            $runner->runPipeline([
                ['/bin/echo', 'hello'],
                ['/usr/bin/false'],
            ]);
            $this->fail('Expected the pipeline to fail');
        } catch (\TypeError $e) {
            // The cleanup path used to re-close handles that the exit-code check
            // had already closed, masking every pipeline error with a fatal.
            $this->fail('Cleanup raised a TypeError: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testFailingMiddleStageIsDetected(): void
    {
        $this->requireBinaries('/bin/echo', '/usr/bin/false', '/bin/cat');

        $this->expectException(RuntimeException::class);

        (new ProcessRunner())->runPipeline([
            ['/bin/echo', 'hello'],
            ['/usr/bin/false'],
            ['/bin/cat'],
        ]);
    }

    public function testStageDescriptionDoesNotLeakThePassphrase(): void
    {
        $this->requireBinaries('/bin/echo', '/usr/bin/false');

        try {
            (new ProcessRunner())->runPipeline([
                ['/bin/echo', 'hello'],
                ['/usr/bin/false', '--passphrase', 'hunter2-should-not-appear'],
            ]);
            $this->fail('Expected the pipeline to fail');
        } catch (RuntimeException $e) {
            // The fallback message names the stage so an operator knows which
            // one died; it must not put the archive's key in the console or log.
            $this->assertStringNotContainsString('hunter2-should-not-appear', $e->getMessage());
            $this->assertStringContainsString('<redacted>', $e->getMessage());
            $this->assertStringContainsString('stage 1', $e->getMessage());
        }
    }

    /**
     * The production hang: `mysql` refused the connection and exited at once,
     * but the parent still held a read end open on the pipe feeding it, so the
     * upstream stage never got EPIPE. It blocked on a full buffer forever and
     * mysql's error was never reported — 55 minutes, no tables, no message.
     */
    public function testLastStageFailingEarlyFailsLoudlyInsteadOfHanging(): void
    {
        $this->requireBinaries('/bin/sh', '/bin/cat');

        $result = $this->runPipelineInSubprocess([
            ['/bin/sh', '-c', 'yes "row-data-padding-line" | head -c 5000000'],
            ['/bin/cat'],
            ['/bin/sh', '-c', 'echo "ERROR 1045: Access denied" >&2; exit 1'],
        ]);

        $this->assertStringContainsString('FAILED:', $result, 'Pipeline should have reported a failure');
        $this->assertStringContainsString('Access denied', $result, "The failing stage's own error must survive");
    }

    public function testStageFloodingStderrCompletesInsteadOfDeadlocking(): void
    {
        $this->requireBinaries('/bin/sh', '/bin/cat');

        // A pipe buffer is ~64KB. Draining only after exit deadlocks here.
        $result = $this->runPipelineInSubprocess([
            ['/bin/sh', '-c', 'echo payload'],
            ['/bin/sh', '-c', 'yes "noisy-diagnostic-line" | head -c 400000 >&2; cat'],
            ['/bin/cat'],
        ]);

        $this->assertStringContainsString('OK', $result);
    }

    public function testLastStageFloodingStdoutCompletesInsteadOfDeadlocking(): void
    {
        $this->requireBinaries('/bin/sh');

        // Nothing consumes the final stage's stdout, but it still must be drained.
        $result = $this->runPipelineInSubprocess([
            ['/bin/sh', '-c', 'echo payload'],
            ['/bin/sh', '-c', 'yes "chatty-output-line" | head -c 400000'],
        ]);

        $this->assertStringContainsString('OK', $result);
    }

    /**
     * Runs the pipeline in a child PHP process under a hard timeout. A
     * regression reintroduces a deadlock, and an in-process test would hang CI
     * forever rather than fail; this turns that into a normal test failure.
     *
     * @param list<list<string>> $pipeline
     */
    private function runPipelineInSubprocess(array $pipeline, int $timeoutSeconds = 30): string
    {
        $root = dirname(__DIR__, 2);

        $script = <<<PHP
        <?php
        require {$this->export($root . '/vendor/autoload.php')};
        try {
            (new DbTools\\Service\\ProcessRunner())->runPipeline({$this->export($pipeline)});
            echo "OK\\n";
        } catch (\\Throwable \$e) {
            echo "FAILED: " . \$e->getMessage() . "\\n";
        }
        PHP;

        $file = tempnam(sys_get_temp_dir(), 'dbtools-pipeline-') . '.php';
        file_put_contents($file, $script);

        try {
            $process = new Process([PHP_BINARY, $file]);
            $process->setTimeout($timeoutSeconds);
            $process->run();

            return $process->getOutput() . $process->getErrorOutput();
        } catch (ProcessTimedOutException) {
            $this->fail(
                "runPipeline() deadlocked: no exit after {$timeoutSeconds}s. "
                . 'A pipeline stage is blocked writing to a pipe nobody is draining.'
            );
        } finally {
            @unlink($file);
        }
    }

    private function export(mixed $value): string
    {
        return var_export($value, true);
    }
}
