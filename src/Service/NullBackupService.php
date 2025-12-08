<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullBackupService implements BackupServiceInterface
{
    public function backup(array $options): string
    {
        throw new LogicException('BackupServiceInterface is not configured. Provide your own implementation that performs backups.');
    }
}
