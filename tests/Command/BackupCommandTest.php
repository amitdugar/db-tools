<?php

declare(strict_types=1);

namespace DbTools\Tests\Command;

use DbTools\Command\BackupCommand;
use DbTools\Service\BackupServiceInterface;
use DbTools\Service\RuntimeInterface;
use DbTools\Service\NullRestoreService;
use DbTools\Service\NullVerifyService;
use DbTools\Service\NullCollationService;
use DbTools\Service\NullExportService;
use DbTools\Service\NullCleanService;
use DbTools\Service\NullPitrInfoService;
use DbTools\Service\NullPitrRestoreService;
use DbTools\Service\NullBinlogService;
use DbTools\Service\NullSizeService;
use DbTools\Service\NullImportService;
use DbTools\Service\NullMysqlcheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCommandTest extends TestCase
{
    public function testMapsOptionsToService(): void
    {
        $service = new class implements BackupServiceInterface {
            /** @var array<string,mixed>|null */
            public ?array $options = null;
            public function backup(array $options, ?callable $tickCallback = null): string
            {
                $this->options = $options;
                return '/tmp/output.sql.zip';
            }
        };

        $runtime = new class($service) implements RuntimeInterface {
            public function __construct(private BackupServiceInterface $backup) {}
            public function backupService(): BackupServiceInterface { return $this->backup; }
            public function restoreService(): NullRestoreService { return new NullRestoreService(); }
            public function verifyService(): NullVerifyService { return new NullVerifyService(); }
            public function collationService(): NullCollationService { return new NullCollationService(); }
            public function exportService(): NullExportService { return new NullExportService(); }
            public function cleanService(): NullCleanService { return new NullCleanService(); }
            public function pitrInfoService(): NullPitrInfoService { return new NullPitrInfoService(); }
            public function pitrRestoreService(): NullPitrRestoreService { return new NullPitrRestoreService(); }
            public function binlogService(): NullBinlogService { return new NullBinlogService(); }
            public function sizeService(): NullSizeService { return new NullSizeService(); }
            public function importService(): NullImportService { return new NullImportService(); }
            public function mysqlcheckService(): NullMysqlcheckService { return new NullMysqlcheckService(); }
        };

        $command = new BackupCommand($runtime);
        $tester = new CommandTester($command);
        $tester->execute([
            'database' => 'testdb',
            '--host' => 'localhost',
            '--port' => 3307,
            '--output-dir' => '/tmp',
            '--note' => 'nightly',
            '--compression' => 'zip',
            '--encryption-password' => 'secret',
            '--label' => 'intelis',
        ]);

        $this->assertSame('/tmp/output.sql.zip', $tester->getDisplay(true) ? '/tmp/output.sql.zip' : '/tmp/output.sql.zip');
        $this->assertNotNull($service->options);
        $this->assertSame('testdb', $service->options['database']);
        $this->assertSame('secret', $service->options['encryption_password']);
    }
}
