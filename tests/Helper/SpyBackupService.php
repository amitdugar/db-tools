<?php

declare(strict_types=1);

namespace DbTools\Tests\Helper;

use DbTools\Service\BackupServiceInterface;

/**
 * Records safety-backup requests without running one.
 */
final class SpyBackupService implements BackupServiceInterface
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function backup(array $options, ?callable $tickCallback = null): string
    {
        $this->calls[] = $options;

        return '/tmp/safety-backup.sql.zip';
    }
}
