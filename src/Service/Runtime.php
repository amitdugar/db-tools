<?php

declare(strict_types=1);

namespace DbTools\Service;

use Psr\Log\LoggerInterface;

final class Runtime implements RuntimeInterface
{
    private BackupServiceInterface $backup;
    private RestoreServiceInterface $restore;
    private VerifyServiceInterface $verify;
    private CollationServiceInterface $collation;
    private ExportServiceInterface $export;
    private CleanServiceInterface $clean;
    private PitrInfoServiceInterface $pitrInfo;
    private PitrRestoreServiceInterface $pitrRestore;
    private BinlogServiceInterface $binlog;
    private SizeServiceInterface $size;
    private ImportServiceInterface $import;
    private MysqlcheckServiceInterface $mysqlcheck;

    public function __construct(?LoggerInterface $logger = null)
    {
        $runner = new ProcessRunner();
        $this->backup = new BackupService($runner, $logger);
        $this->restore = new RestoreService($runner, $this->backup, $logger);
        $this->verify = new VerifyService($runner, $logger);
        $this->collation = new CollationService($runner, $logger);
        $this->export = new ExportService($runner, $logger);
        $this->binlog = new BinlogService($runner, $logger);
        $this->clean = new CleanService($this->binlog);
        $this->pitrInfo = new PitrInfoService();
        $this->pitrRestore = new PitrRestoreService($runner, $logger);
        $this->size = new SizeService($runner, $logger);
        $this->import = new ImportService($runner, $logger);
        $this->mysqlcheck = new MysqlcheckService($runner, $logger);
    }

    public function backupService(): BackupServiceInterface
    {
        return $this->backup;
    }

    public function restoreService(): RestoreServiceInterface
    {
        return $this->restore;
    }

    public function verifyService(): VerifyServiceInterface
    {
        return $this->verify;
    }

    public function collationService(): CollationServiceInterface
    {
        return $this->collation;
    }

    public function exportService(): ExportServiceInterface
    {
        return $this->export;
    }

    public function cleanService(): CleanServiceInterface
    {
        return $this->clean;
    }

    public function pitrInfoService(): PitrInfoServiceInterface
    {
        return $this->pitrInfo;
    }

    public function pitrRestoreService(): PitrRestoreServiceInterface
    {
        return $this->pitrRestore;
    }

    public function binlogService(): BinlogServiceInterface
    {
        return $this->binlog;
    }

    public function sizeService(): SizeServiceInterface
    {
        return $this->size;
    }

    public function importService(): ImportServiceInterface
    {
        return $this->import;
    }

    public function mysqlcheckService(): MysqlcheckServiceInterface
    {
        return $this->mysqlcheck;
    }
}
