<?php

declare(strict_types=1);

namespace DbTools\Service;

interface RuntimeInterface
{
    public function backupService(): BackupServiceInterface;
    public function restoreService(): RestoreServiceInterface;
    public function verifyService(): VerifyServiceInterface;
    public function collationService(): CollationServiceInterface;
    public function exportService(): ExportServiceInterface;
    public function cleanService(): CleanServiceInterface;
    public function pitrInfoService(): PitrInfoServiceInterface;
    public function pitrRestoreService(): PitrRestoreServiceInterface;
    public function binlogService(): BinlogServiceInterface;
    public function sizeService(): SizeServiceInterface;
    public function importService(): ImportServiceInterface;
    public function mysqlcheckService(): MysqlcheckServiceInterface;
}
