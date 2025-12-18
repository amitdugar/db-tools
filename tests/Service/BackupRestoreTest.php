<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Service\BackupService;
use DbTools\Service\RestoreService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class BackupRestoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbtools-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
        ArchiveUtility::setMaxFileSize(null);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        $runner = new FakeProcessRunner();
        $backup = new BackupService($runner);
        $restore = new RestoreService($runner, $backup);

        $archive = $backup->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
        ]);

        $this->assertFileExists($archive);

        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);

        $this->assertNotNull($runner->lastImport);
        $this->assertStringContainsString('CREATE TABLE', (string) $runner->lastImport);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
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
