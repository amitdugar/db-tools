<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\PitrRestoreService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class PitrRestoreServiceTest extends TestCase
{
    public function testAppliesBinlog(): void
    {
        $runner = new FakeProcessRunner();
        $service = new PitrRestoreService($runner);

        $service->restore([
            'database' => 'testdb',
            'binlogs' => ['/tmp/binlog.000001'],
            'to' => '2024-01-01 00:00:00',
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertGreaterThanOrEqual(2, count($runner->invocations));
    }
}
