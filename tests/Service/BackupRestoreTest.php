<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Service\BackupService;
use DbTools\Service\RestoreService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class BackupRestoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbtools-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
        ArchiveUtility::setMaxFileSize(null);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        $runner = new FakeProcessRunner();
        $backup = new BackupService($runner);
        $restore = new RestoreService($runner, $backup);

        $archive = $backup->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
        ]);

        $this->assertFileExists($archive);

        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);

        $this->assertNotNull($runner->lastImport);
        $this->assertStringContainsString('CREATE TABLE', (string) $runner->lastImport);
    }

    public function testFixedEncryptionPasswordRoundTripAndTokenlessFilename(): void
    {
        $runner = new FakeProcessRunner();
        $backup = new BackupService($runner);
        $restore = new RestoreService($runner, $backup);

        $archive = $backup->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'password' => 'dbsecret',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
            'encryption_password' => 'FIXEDKEY123',
        ]);

        $this->assertFileExists($archive);

        // A fixed/explicit passphrase must NOT embed the 32-char random token in the
        // filename: the archive is portable and can only be opened with that same
        // password (not reconstructable from the DB password + filename).
        $this->assertDoesNotMatchRegularExpression('/-\d{8}-\d{6}-[A-Za-z0-9]{32}/', basename($archive));

        // Restoring WITH the same password round-trips.
        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'encryption_password' => 'FIXEDKEY123',
            'skip_safety_backup' => true,
        ]);
        $this->assertStringContainsString('CREATE TABLE', (string) $runner->lastImport);
    }

    public function testFixedEncryptionPasswordArchiveRequiresThatPassword(): void
    {
        $runner = new FakeProcessRunner();
        $backup = new BackupService($runner);
        $restore = new RestoreService($runner, $backup);

        $archive = $backup->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'password' => 'dbsecret',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
            'encryption_password' => 'FIXEDKEY123',
        ]);

        // Without the password there is no way to derive it (no filename token), so
        // restore must fail rather than silently produce garbage.
        $this->expectException(\RuntimeException::class);
        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'skip_safety_backup' => true,
        ]);
    }

    public function testDefaultSchemeKeepsTokenAndAutoDerivesOnRestore(): void
    {
        $runner = new FakeProcessRunner();
        $backup = new BackupService($runner);
        $restore = new RestoreService($runner, $backup);

        // Default scheme: encrypt on, NO explicit password -> DB_password + token,
        // token embedded in the filename. This is the unchanged legacy behavior.
        $archive = $backup->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'password' => 'dbsecret',
            'output_dir' => $this->tmpDir,
            'compression' => 'zip',
            'encrypt' => true,
        ]);

        $this->assertMatchesRegularExpression('/-\d{8}-\d{6}-[A-Za-z0-9]{32}/', basename($archive));

        // Restore WITHOUT a password still works: the passphrase is reconstructed from
        // the DB password + the token in the filename.
        $restore->restore([
            'database' => 'testdb',
            'archive' => $archive,
            'host' => 'localhost',
            'user' => 'root',
            'password' => 'dbsecret',
            'skip_safety_backup' => true,
        ]);
        $this->assertStringContainsString('CREATE TABLE', (string) $runner->lastImport);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
