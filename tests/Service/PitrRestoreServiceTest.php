<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\PitrRestoreService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PitrRestoreServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/dbtools-pitr-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0777, true)) {
            self::markTestSkipped('Could not create a working directory');
        }
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * The binlog stage is built as [binary, --stop-datetime=…, path, --database=…].
     *
     * @return list<list<string>>
     */
    private function binlogCommands(FakeProcessRunner $runner): array
    {
        $found = [];
        foreach ($runner->invocations as $invocation) {
            $cmd = $invocation['cmd'];
            $tool = basename($cmd[0] ?? '');
            if ($tool === 'mysqlbinlog' || $tool === 'mariadb-binlog') {
                $found[] = $cmd;
            }
        }
        return $found;
    }

    /**
     * @return list<string>
     */
    private function appliedBinlogs(FakeProcessRunner $runner): array
    {
        return array_map(static fn(array $cmd): string => $cmd[2], $this->binlogCommands($runner));
    }

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

    /**
     * The recovery target is the whole point of PITR — replaying past it would
     * reapply the very statements the operator is trying to undo.
     */
    public function testStopDatetimeAndDatabaseFilterArePassedToEveryBinlog(): void
    {
        $runner = new FakeProcessRunner();

        (new PitrRestoreService($runner))->restore([
            'database' => 'testdb',
            'binlogs' => ['/tmp/binlog.000001', '/tmp/binlog.000002'],
            'to' => '2024-06-01 12:30:00',
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $commands = $this->binlogCommands($runner);
        $this->assertCount(2, $commands);

        foreach ($commands as $cmd) {
            $this->assertContains('--stop-datetime=2024-06-01 12:30:00', $cmd);
            $this->assertContains('--database=testdb', $cmd);
        }
    }

    /**
     * Binlogs are an ordered sequence; replaying them out of order corrupts the
     * recovered state.
     */
    public function testBinlogsAreAppliedInTheGivenOrder(): void
    {
        $runner = new FakeProcessRunner();

        (new PitrRestoreService($runner))->restore([
            'database' => 'testdb',
            'binlogs' => ['/tmp/binlog.000001', '/tmp/binlog.000002', '/tmp/binlog.000003'],
            'to' => '2024-01-01 00:00:00',
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertSame(
            ['/tmp/binlog.000001', '/tmp/binlog.000002', '/tmp/binlog.000003'],
            $this->appliedBinlogs($runner)
        );
    }

    public function testFailsWhenNoBinlogsResolve(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No binlogs found for PITR');

        (new PitrRestoreService(new FakeProcessRunner()))->restore([
            'database' => 'testdb',
            'to' => '2024-01-01 00:00:00',
            'host' => 'localhost',
            'user' => 'root',
        ]);
    }

    public function testRequiresDatabaseAndRecoveryTarget(): void
    {
        $partials = [
            ['to' => '2024-01-01 00:00:00'],
            ['database' => 'testdb'],
        ];

        foreach ($partials as $partial) {
            try {
                (new PitrRestoreService(new FakeProcessRunner()))->restore($partial);
                $this->fail('Expected a missing required option to fail: ' . json_encode($partial));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Missing required option', $e->getMessage());
            }
        }
    }

    public function testResolvesBinlogsFromMetaFileRelativeToItsRecordedDirectory(): void
    {
        $meta = $this->tmpDir . '/backup.meta.json';
        file_put_contents($meta, (string) json_encode([
            'binlog_dir' => '/var/log/mysql',
            'binlogs' => ['mysql-bin.000010', 'mysql-bin.000011'],
        ]));

        $runner = new FakeProcessRunner();

        (new PitrRestoreService($runner))->restore([
            'database' => 'testdb',
            'to' => '2024-01-01 00:00:00',
            'meta' => $meta,
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertSame(
            ['/var/log/mysql/mysql-bin.000010', '/var/log/mysql/mysql-bin.000011'],
            $this->appliedBinlogs($runner)
        );
    }

    public function testExplicitBinlogsWinOverTheMetaFile(): void
    {
        $meta = $this->tmpDir . '/backup.meta.json';
        file_put_contents($meta, (string) json_encode([
            'binlog_dir' => '/var/log/mysql',
            'binlogs' => ['mysql-bin.000010'],
        ]));

        $runner = new FakeProcessRunner();

        (new PitrRestoreService($runner))->restore([
            'database' => 'testdb',
            'to' => '2024-01-01 00:00:00',
            'meta' => $meta,
            'binlogs' => ['/explicit/binlog.000001'],
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertSame(['/explicit/binlog.000001'], $this->appliedBinlogs($runner));
    }

    public function testMissingMetaFileIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Meta file not found');

        (new PitrRestoreService(new FakeProcessRunner()))->restore([
            'database' => 'testdb',
            'to' => '2024-01-01 00:00:00',
            'meta' => $this->tmpDir . '/absent.meta.json',
            'host' => 'localhost',
            'user' => 'root',
        ]);
    }
}
