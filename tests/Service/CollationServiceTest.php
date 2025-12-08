<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\CollationService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class CollationServiceTest extends TestCase
{
    public function testRunsAlterDatabase(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        $service->changeCollation([
            'database' => 'testdb',
            'collation' => 'utf8mb4_unicode_ci',
            'charset' => 'utf8mb4',
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertNotEmpty($runner->invocations);
        $cmd = $runner->invocations[0]['cmd'];
        $this->assertSame('mysql', $cmd[0]);
        $this->assertStringContainsString('ALTER DATABASE', implode(' ', $cmd));
    }
}
