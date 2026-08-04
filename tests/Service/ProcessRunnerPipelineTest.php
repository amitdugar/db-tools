<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
}
