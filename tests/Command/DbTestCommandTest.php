<?php

declare(strict_types=1);

namespace DbTools\Tests\Command;

use DbTools\Command\DbTestCommand;
use DbTools\Service\BinaryResolver;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DbTestCommandTest extends TestCase
{
    private function tester(FakeProcessRunner $runner): CommandTester
    {
        return new CommandTester(
            new DbTestCommand(null, $runner, new BinaryResolver(['mysql' => 'mysql']))
        );
    }

    public function testReportsMariaDbFlavorAndUptime(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["11.4.8-MariaDB-log\nUptime\t93784"];

        $tester = $this->tester($runner);
        $exit = $tester->execute(['--host' => 'db.example', '--user' => 'root']);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('MariaDB 11.4.8', $display);
        $this->assertStringContainsString('11.4.8-MariaDB-log', $display);
        $this->assertStringContainsString('1d 2h 3m', $display);
    }

    public function testReportsMysqlFlavor(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["8.0.36\nUptime\t60"];

        $tester = $this->tester($runner);
        $tester->execute(['--host' => 'db.example', '--user' => 'root']);

        $this->assertStringContainsString('MySQL 8.0.36', $tester->getDisplay());
    }

    public function testUsesAStatusQueryThatWorksOnBothFlavors(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["11.4.8-MariaDB\nUptime\t10"];

        $this->tester($runner)->execute(['--host' => 'db.example', '--user' => 'root']);

        $sql = implode(' ', $runner->invocations[0]['cmd']);
        // performance_schema.global_status does not exist on MariaDB.
        $this->assertStringNotContainsString('performance_schema', $sql);
        $this->assertStringContainsString('SHOW GLOBAL STATUS', $sql);
    }

    public function testMissingUptimeStillSucceeds(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["10.11.6-MariaDB"];

        $tester = $this->tester($runner);

        $this->assertSame(0, $tester->execute(['--host' => 'db.example', '--user' => 'root']));
        $this->assertStringContainsString('MariaDB 10.11.6', $tester->getDisplay());
    }

    public function testConnectionFailureIsReported(): void
    {
        $runner = new FakeProcessRunner();
        $runner->failure = 'Access denied for user';

        $tester = $this->tester($runner);
        $exit = $tester->execute(['--host' => 'db.example', '--user' => 'root']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Access denied for user', $tester->getDisplay());
    }
}
