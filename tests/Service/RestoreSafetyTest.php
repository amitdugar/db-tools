<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\BackupService;
use DbTools\Service\RestoreService;
use DbTools\Tests\Helper\FakeProcessRunner;
use DbTools\Tests\Helper\SpyBackupService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Restore is the most destructive thing this tool does: it drops the target
 * database before importing. These cover the guardrails around that — that the
 * safety backup happens, and that the DROP names the database the caller asked
 * for and nothing else.
 */
final class RestoreSafetyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/dbtools-restore-safety-' . bin2hex(random_bytes(6));
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

    private function makeArchive(FakeProcessRunner $runner, string $database): string
    {
        return (new BackupService($runner))->backup([
            'database' => $database,
            'host' => 'localhost',
            'user' => 'root',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
        ]);
    }

    /**
     * @return list<string>
     */
    private function sqlStatements(FakeProcessRunner $runner): array
    {
        $sql = [];
        foreach ($runner->invocations as $invocation) {
            $cmd = $invocation['cmd'];
            foreach ($cmd as $i => $arg) {
                if ($arg === '-e' && isset($cmd[$i + 1])) {
                    $sql[] = (string) $cmd[$i + 1];
                }
            }
        }
        return $sql;
    }

    public function testTakesASafetyBackupBeforeDroppingAnything(): void
    {
        $runner = new FakeProcessRunner();
        $archive = $this->makeArchive($runner, 'testdb');

        $spy = new SpyBackupService();
        $restore = new RestoreService($runner, $spy);

        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertCount(1, $spy->calls, 'Restore did not take a safety backup');
        $this->assertSame('testdb', $spy->calls[0]['database']);
        $this->assertStringContainsString('pre-restore', (string) $spy->calls[0]['label']);
    }

    public function testSkipSafetyBackupIsHonoured(): void
    {
        $runner = new FakeProcessRunner();
        $archive = $this->makeArchive($runner, 'testdb');

        $spy = new SpyBackupService();
        $restore = new RestoreService($runner, $spy);

        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);

        $this->assertSame([], $spy->calls, 'Safety backup ran despite skip_safety_backup');
    }

    public function testDropsAndRecreatesTheTargetDatabase(): void
    {
        $runner = new FakeProcessRunner();
        $archive = $this->makeArchive($runner, 'testdb');

        (new RestoreService($runner, new SpyBackupService()))->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);

        $sql = implode(' | ', $this->sqlStatements($runner));

        $this->assertStringContainsString('DROP DATABASE IF EXISTS `testdb`', $sql);
        $this->assertStringContainsString('CREATE DATABASE `testdb`', $sql);
    }

    /**
     * Restoring an archive taken from one database into a differently-named one
     * is a normal operation (clone prod into staging). The DROP must follow the
     * requested target, never the name baked into the archive filename.
     */
    public function testDropsOnlyTheRequestedDatabaseNotTheArchivesOrigin(): void
    {
        $runner = new FakeProcessRunner();
        $archive = $this->makeArchive($runner, 'production');

        (new RestoreService($runner, new SpyBackupService()))->restore([
            'database' => 'staging',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);

        $sql = implode(' | ', $this->sqlStatements($runner));

        $this->assertStringContainsString('DROP DATABASE IF EXISTS `staging`', $sql);
        $this->assertStringNotContainsString('DROP DATABASE IF EXISTS `production`', $sql);
    }

    /**
     * The archive check used to run *after* recreateDatabase(), so a mistyped
     * path dropped the database and only then reported the problem — with
     * --skip-safety-backup that is unrecoverable.
     */
    public function testMissingArchiveFailsBeforeTouchingTheDatabase(): void
    {
        $runner = new FakeProcessRunner();

        try {
            (new RestoreService($runner, new SpyBackupService()))->restore([
                'database' => 'testdb',
                'archive' => $this->tmpDir . '/does-not-exist.sql.zip',
                'host' => 'localhost',
                'user' => 'root',
                'skip_safety_backup' => true,
            ]);
            $this->fail('Expected a missing archive to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $sql = implode(' | ', $this->sqlStatements($runner));
        $this->assertStringNotContainsString('DROP DATABASE', $sql, 'Database was dropped before the archive was validated');
    }

    /**
     * Same ordering problem for an encrypted archive with no way to open it.
     */
    public function testEncryptedArchiveWithoutPasswordFailsBeforeTouchingTheDatabase(): void
    {
        $runner = new FakeProcessRunner();

        // A .gpg name with no embedded token and no password supplied: nothing
        // can open this, so it must fail before the DROP.
        $archive = $this->tmpDir . '/fixed-20260101-000000.sql.zst.gpg';
        file_put_contents($archive, 'not really encrypted');

        try {
            (new RestoreService($runner, new SpyBackupService()))->restore([
                'database' => 'testdb',
                'archive' => $archive,
                'host' => 'localhost',
                'user' => 'root',
                'skip_safety_backup' => true,
            ]);
            $this->fail('Expected an unopenable encrypted archive to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('encrypted', $e->getMessage());
        }

        $sql = implode(' | ', $this->sqlStatements($runner));
        $this->assertStringNotContainsString('DROP DATABASE', $sql, 'Database was dropped before the passphrase was validated');
    }
}
