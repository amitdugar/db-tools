<?php

declare(strict_types=1);

namespace DbTools\Tests\Command;

use DbTools\Command\RestoreCommand;
use PHPUnit\Framework\TestCase;

/**
 * The size shown after "Restore completed". A streamed restore never writes the
 * SQL out, so its size is unknown, and it was shown as "0 B raw": a restore
 * that worked read as one that restored nothing.
 */
final class RestoreSizeInfoTest extends TestCase
{
    public function testAStreamedRestoreShowsOnlyTheArchiveSize(): void
    {
        $this->assertSame('53.7 KB', $this->sizeInfo(54_989, 0));
    }

    public function testAnExtractedRestoreShowsBothSizes(): void
    {
        $this->assertSame('53.7 KB compressed → 1 MB raw', $this->sizeInfo(54_989, 1_048_576));
    }

    public function testAnUncompressedArchiveShowsOneSize(): void
    {
        $this->assertSame('1 MB', $this->sizeInfo(1_048_576, 1_048_576));
    }

    private function sizeInfo(int $archiveSize, int $sqlSize): string
    {
        $method = new \ReflectionMethod(RestoreCommand::class, 'sizeInfo');
        $command = (new \ReflectionClass(RestoreCommand::class))->newInstanceWithoutConstructor();

        return $method->invoke($command, $archiveSize, $sqlSize);
    }
}
