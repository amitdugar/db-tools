<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\TempFileRegistry;
use PHPUnit\Framework\TestCase;

final class TempFileRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbtools-registry-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testCleanupRemovesRegisteredFiles(): void
    {
        $registry = new TempFileRegistry();
        $file = $this->tmpDir . '/test.sql';
        file_put_contents($file, 'data');

        $registry->register($file);
        $this->assertFileExists($file);

        $registry->cleanup();
        $this->assertFileDoesNotExist($file);
        $this->assertSame([], $registry->registered());
    }

    public function testCleanupRemovesDirectoryRecursively(): void
    {
        $registry = new TempFileRegistry();
        $dir = $this->tmpDir . '/nested';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.txt', 'a');
        mkdir($dir . '/sub', 0777, true);
        file_put_contents($dir . '/sub/b.txt', 'b');

        $registry->register($dir);
        $registry->cleanup();

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupIsIdempotent(): void
    {
        $registry = new TempFileRegistry();
        $file = $this->tmpDir . '/idempotent.sql';
        file_put_contents($file, 'data');

        $registry->register($file);
        $registry->cleanup();
        // Second call should not error
        $registry->cleanup();

        $this->assertFileDoesNotExist($file);
        $this->assertSame([], $registry->registered());
    }

    public function testReleaseRemovesFromRegistryWithoutDeletion(): void
    {
        $registry = new TempFileRegistry();
        $file = $this->tmpDir . '/keep-me.sql';
        file_put_contents($file, 'keep');

        $registry->register($file);
        $registry->release($file);
        $registry->cleanup();

        // File should still exist on disk
        $this->assertFileExists($file);
        $this->assertSame([], $registry->registered());
    }

    public function testCreateTempDirRegistersAndCreatesDirectory(): void
    {
        $registry = new TempFileRegistry();
        $dir = $registry->createTempDir($this->tmpDir, 'test-prefix-');

        $this->assertDirectoryExists($dir);
        $this->assertStringStartsWith($this->tmpDir . DIRECTORY_SEPARATOR . 'test-prefix-', $dir);
        $this->assertContains($dir, $registry->registered());

        $registry->cleanup();
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupOnException(): void
    {
        $registry = new TempFileRegistry();
        $file = $this->tmpDir . '/exception.sql';
        file_put_contents($file, 'data');
        $registry->register($file);

        try {
            throw new \RuntimeException('simulated failure');
        } catch (\RuntimeException) {
            // In real code, this would be in a finally block
        } finally {
            $registry->cleanup();
        }

        $this->assertFileDoesNotExist($file);
    }

    public function testDuplicateRegisterDoesNotDoubleTrack(): void
    {
        $registry = new TempFileRegistry();
        $file = $this->tmpDir . '/dup.sql';
        file_put_contents($file, 'data');

        $registry->register($file);
        $registry->register($file);

        $this->assertCount(1, $registry->registered());
    }

    public function testResolveTempBasePreference(): void
    {
        // Explicit override wins
        $this->assertSame('/custom/dir', TempFileRegistry::resolveTempBase('/custom/dir'));

        // Null falls through to env or sys_get_temp_dir
        $result = TempFileRegistry::resolveTempBase(null);
        $this->assertNotEmpty($result);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
