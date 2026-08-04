<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\BackupService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * These drive the real shell pipeline rather than a fake runner, because the
 * bugs they cover live in process handling itself: exit-status propagation and
 * handle lifetime. A fake would not exercise either.
 *
 * They skip themselves on platforms without false(1), which covers Windows.
 */
final class BackupPipelineTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/dbtools-pipeline-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0777, true)) {
            self::markTestSkipped('Could not create a working directory');
        }
        $this->workDir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function options(array $extra = []): array
    {
        return $extra + [
            'database' => 'anything',
            'host' => '127.0.0.1',
            'user' => 'nobody',
            'output_dir' => $this->workDir,
            'encrypt' => false,
        ];
    }

    public function testFailingDumpDoesNotProduceAnArchive(): void
    {
        // A shell pipeline reports only its last stage, so before pipefail a
        // dump that died still exited 0 — the compressor downstream succeeded
        // on the truncated input and a valid-looking, incomplete archive was
        // written and reported as a successful backup.
        putenv('DBTOOLS_MYSQLDUMP_BIN=' . $this->falseBinary());

        try {
            $service = new BackupService();

            $this->expectException(RuntimeException::class);
            $service->backup($this->options());
        } finally {
            putenv('DBTOOLS_MYSQLDUMP_BIN');
        }
    }

    public function testFailingDumpLeavesNoPartialFileBehind(): void
    {
        putenv('DBTOOLS_MYSQLDUMP_BIN=' . $this->falseBinary());

        try {
            (new BackupService())->backup($this->options());
            $this->fail('Expected the backup to fail');
        } catch (RuntimeException) {
            $leftovers = array_diff(scandir($this->workDir) ?: [], ['.', '..']);
            $this->assertSame([], array_values($leftovers), 'A failed backup left files behind');
        } finally {
            putenv('DBTOOLS_MYSQLDUMP_BIN');
        }
    }

    public function testPipelineFailureReportsTheUnderlyingErrorNotATypeError(): void
    {
        // The failure path used to call proc_close() on an already-closed
        // handle. @ does not suppress the resulting TypeError, so every backup
        // failure surfaced as an unrelated fatal instead of its real cause.
        putenv('DBTOOLS_MYSQLDUMP_BIN=' . $this->falseBinary());

        try {
            (new BackupService())->backup($this->options());
            $this->fail('Expected the backup to fail');
        } catch (\TypeError $e) {
            $this->fail('Failure path raised a TypeError: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            putenv('DBTOOLS_MYSQLDUMP_BIN');
        }
    }

    public function testMissingOutputDirectoryIsCreated(): void
    {
        $nested = $this->workDir . '/a/b/c';
        putenv('DBTOOLS_MYSQLDUMP_BIN=' . $this->falseBinary());

        try {
            (new BackupService())->backup($this->options(['output_dir' => $nested]));
        } catch (RuntimeException) {
            // The dump is meant to fail here; the directory is the assertion.
        } finally {
            putenv('DBTOOLS_MYSQLDUMP_BIN');
        }

        $this->assertDirectoryExists($nested);

        foreach (glob($nested . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($nested);
        @rmdir($this->workDir . '/a/b');
        @rmdir($this->workDir . '/a');
    }

    private function falseBinary(): string
    {
        foreach (['/usr/bin/false', '/bin/false'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        self::markTestSkipped('No false(1) available to simulate a failing dump');
    }
}
