<?php

declare(strict_types=1);

namespace DbTools\Service;

final class NullRuntime implements RuntimeInterface
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

    public function __construct(
        ?BackupServiceInterface $backup = null,
        ?RestoreServiceInterface $restore = null,
        ?VerifyServiceInterface $verify = null,
        ?CollationServiceInterface $collation = null,
        ?ExportServiceInterface $export = null,
        ?CleanServiceInterface $clean = null,
        ?PitrInfoServiceInterface $pitrInfo = null,
        ?PitrRestoreServiceInterface $pitrRestore = null,
        ?BinlogServiceInterface $binlog = null,
    ) {
        $this->backup = $backup ?? new NullBackupService();
        $this->restore = $restore ?? new NullRestoreService();
        $this->verify = $verify ?? new NullVerifyService();
        $this->collation = $collation ?? new NullCollationService();
        $this->export = $export ?? new NullExportService();
        $this->clean = $clean ?? new NullCleanService();
        $this->pitrInfo = $pitrInfo ?? new NullPitrInfoService();
        $this->pitrRestore = $pitrRestore ?? new NullPitrRestoreService();
        $this->binlog = $binlog ?? new NullBinlogService();
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
}
