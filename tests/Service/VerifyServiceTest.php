<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Service\VerifyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VerifyServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbtools-verify-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
        ArchiveUtility::setMaxFileSize(null);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testVerifyZip(): void
    {
        $src = $this->tmpDir . '/file.txt';
        file_put_contents($src, 'hello');
        $zip = ArchiveUtility::compressFile($src, $this->tmpDir . '/file.zip', ArchiveUtility::BACKEND_ZIP);

        $service = new VerifyService();
        $service->verify(['target' => $zip]);

        $this->assertTrue(true); // no exception
    }

    public function testVerifyFailsOnMissing(): void
    {
        $service = new VerifyService();
        $this->expectException(RuntimeException::class);
        $service->verify(['target' => $this->tmpDir . '/missing.zip']);
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
