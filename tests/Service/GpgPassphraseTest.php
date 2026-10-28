<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Service\BackupService;
use DbTools\Service\GpgPassphrase;
use DbTools\Service\ImportService;
use DbTools\Service\RestoreService;
use DbTools\Service\VerifyService;
use DbTools\Service\TempFileRegistry;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The gpg passphrase goes to gpg in a file, not on its command line, where any
 * user on the host read it with `ps` for the whole backup.
 */
final class GpgPassphraseTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbtools-pass-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
        ArchiveUtility::setMaxFileSize(null);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testThePassphraseIsInAPrivateFileUntilTheObjectGoes(): void
    {
        $registry = new TempFileRegistry();
        $passphrase = new GpgPassphrase('FIXEDKEY123', $registry);

        [$option, $path] = $passphrase->args();
        $this->assertSame('--passphrase-file', $option);
        $this->assertStringNotContainsString('FIXEDKEY123', implode(' ', $passphrase->args()));
        $this->assertSame('FIXEDKEY123', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertContains($path, $registry->registered(), 'removed by an interrupted run too');

        unset($passphrase);

        $this->assertFileDoesNotExist($path);
        $this->assertNotContains($path, $registry->registered());
    }

    public function testAStaleTmpdirFallsBackToAWritableTempDirectory(): void
    {
        // Inherited by cron or a service unit. A fresh process, as cron starts one:
        // PHP reads TMPDIR once, at its first temp lookup, and this one has run.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ';'
            . '$p = new DbTools\\Service\\GpgPassphrase("FIXEDKEY123");'
            . '[, $path] = $p->args();'
            . 'echo file_get_contents($path), "|", is_file($path) ? "kept" : "gone";'
            . 'unset($p); echo "|", is_file($path) ? "kept" : "gone";';
        $env = ['TMPDIR' => $this->tmpDir . '/gone'];
        $child = new Process([PHP_BINARY, '-d', 'sys_temp_dir=', '-r', $script], null, $env);
        $child->run();

        $this->assertSame('FIXEDKEY123|kept|gone', $child->getOutput(), $child->getErrorOutput());
    }

    public function testATempDirTheOperatorChoseIsKeptTo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no-such-dir');

        new GpgPassphrase('FIXEDKEY123', null, $this->tmpDir . '/no-such-dir');
    }

    public function testAPassphraseWithALineBreakStaysOnTheCommandLine(): void
    {
        // In a file gpg would read up to the break and no longer open its archives.
        $passphrase = new GpgPassphrase("two\nlines");

        $this->assertSame(['--passphrase', "two\nlines"], $passphrase->args());
    }

    public function testAnArchiveMadeThroughTheFileOpensWithThePassphraseAsBefore(): void
    {
        if ((new ExecutableFinder())->find('gpg') === null) {
            $this->markTestSkipped('gpg is not installed');
        }
        $plain = $this->tmpDir . '/plain.sql';
        file_put_contents($plain, "CREATE TABLE t (id INT);\n");
        $passphrase = new GpgPassphrase('FIXEDKEY123');

        $encrypt = new Process([
            'gpg', '--batch', '--yes', ...$passphrase->args(),
            '--symmetric', '--cipher-algo', 'AES256', '--output', $plain . '.gpg', $plain,
        ]);
        $encrypt->mustRun();
        // A backup made before this change, or restored by an older db-tools.
        $decrypt = new Process([
            'gpg', '--batch', '--yes', '--quiet', '--passphrase', 'FIXEDKEY123', '--decrypt', $plain . '.gpg',
        ]);
        $decrypt->mustRun();

        $this->assertSame("CREATE TABLE t (id INT);\n", $decrypt->getOutput());
    }

    public function testAStreamedBackupIsEncryptedWithoutThePassphraseOnAnyCommandLine(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('gpg') === null || $finder->find('gzip') === null) {
            $this->markTestSkipped('gpg and gzip are needed');
        }
        // A stand-in for mysqldump: the pipeline, the compressor and gpg are real.
        // It writes down its parent's command line, the shell running the pipeline
        // with gpg in it, as `ps` shows it to every user on the host.
        $dump = $this->tmpDir . '/mysqldump';
        $seen = $this->tmpDir . '/pipeline-args.txt';
        // -ww: without a terminal, Linux ps cuts the line at 80 columns.
        file_put_contents($dump, "#!/bin/sh\nps -ww -o args= -p \$PPID > " . escapeshellarg($seen)
            . "\necho 'CREATE TABLE t (id INT);'\n");
        chmod($dump, 0755);
        putenv("DBTOOLS_MYSQLDUMP_BIN=$dump");
        $before = glob(sys_get_temp_dir() . '/dbtools-pass-*') ?: [];

        try {
            $archive = (new BackupService(new FakeProcessRunner()))->backup([
                'database' => 'testdb',
                'host' => 'localhost',
                'user' => 'root',
                'output_dir' => $this->tmpDir,
                'compression' => 'gzip',
                'encryption_password' => 'FIXEDKEY123',
            ]);
        } finally {
            putenv('DBTOOLS_MYSQLDUMP_BIN');
        }

        $this->assertStringEndsWith('.gpg', $archive);
        $args = (string) file_get_contents($seen);
        $this->assertStringContainsString('gpg', $args, 'the pipeline was seen');
        $this->assertStringContainsString('--passphrase-file', $args);
        $this->assertStringNotContainsString('FIXEDKEY123', $args);
        $decrypt = Process::fromShellCommandline(
            'gpg --batch --yes --quiet --passphrase FIXEDKEY123 --decrypt ' . escapeshellarg($archive) . ' | gzip -dc'
        );
        $decrypt->mustRun();
        $this->assertStringContainsString('CREATE TABLE t', $decrypt->getOutput());
        $this->assertSame($before, glob(sys_get_temp_dir() . '/dbtools-pass-*') ?: [], 'no passphrase file left');
    }

    public function testEncryptingAFileRunsGpgWithoutThePassphrase(): void
    {
        $runner = new FakeProcessRunner();
        $plain = $this->tmpDir . '/backup.sql.zst';
        file_put_contents($plain, 'x');

        $encrypt = new \ReflectionMethod(BackupService::class, 'encryptFile');
        $encrypt->invoke(new BackupService($runner), $plain, 'FIXEDKEY123');

        $this->assertCount(1, $runner->invocations);
        $this->assertSame('gpg', $runner->invocations[0]['cmd'][0]);
        $this->assertContains('--passphrase-file', $runner->invocations[0]['cmd']);
        $this->assertStringNotContainsString('FIXEDKEY123', implode(' ', $runner->invocations[0]['cmd']));
    }
    /** @return iterable<string, array{class-string, string, list<mixed>}> */
    public static function decryptingCalls(): iterable
    {
        yield 'restore, to a file' => [RestoreService::class, 'decryptFile', ['passphrase']];
        yield 'restore, legacy openssl' => [RestoreService::class, 'decryptFileLegacy', ['FIXEDKEY123']];
        yield 'import, to a file' => [ImportService::class, 'decryptFile', ['FIXEDKEY123']];
        yield 'import, trying a passphrase' => [ImportService::class, 'tryDecrypt', ['FIXEDKEY123']];
    }

    /**
     * @param class-string $class
     * @param list<mixed> $password
     */
    #[DataProvider('decryptingCalls')]
    public function testDecryptingRunsNoCommandThatCarriesThePassphrase(string $class, string $method, array $password): void
    {
        $runner = new FakeProcessRunner();
        $service = $class === RestoreService::class
            ? new RestoreService($runner, new BackupService($runner))
            : new ImportService($runner);
        $secret = $password === ['passphrase'] ? new GpgPassphrase('FIXEDKEY123') : $password[0];
        $encrypted = $this->tmpDir . '/backup.sql.zst.gpg';
        file_put_contents($encrypted, 'x');

        (new \ReflectionMethod($class, $method))->invoke($service, $encrypted, $secret, $this->tmpDir);

        $this->assertCount(1, $runner->invocations);
        $call = $runner->invocations[0];
        $this->assertStringNotContainsString('FIXEDKEY123', implode(' ', $call['cmd']));
        if ($call['cmd'][0] === 'openssl') {
            $this->assertSame('FIXEDKEY123', $call['env']['DBTOOLS_LEGACY_PASSPHRASE'] ?? null, 'from the environment');
        } else {
            $this->assertContains('--passphrase-file', $call['cmd']);
        }
    }

    public function testVerifyingRunsNoCommandThatCarriesThePassphrase(): void
    {
        foreach (['backup.sql.zst.gpg', 'backup.sql.zst.enc'] as $name) {
            $runner = new FakeProcessRunner();
            $archive = $this->tmpDir . '/' . $name;
            file_put_contents($archive, 'x');
            $verify = new VerifyService($runner);
            $method = str_ends_with($name, '.gpg') ? 'verifyGpgFile' : 'verifyEncryptedFile';

            try {
                (new \ReflectionMethod(VerifyService::class, $method))->invoke($verify, $archive, 'FIXEDKEY123');
            } catch (\RuntimeException) {
                // The fake decrypts nothing, so validating the result fails. The call is what is checked.
            }

            $this->assertNotSame([], $runner->invocations, $name);
            foreach ($runner->invocations as $call) {
                $this->assertStringNotContainsString('FIXEDKEY123', implode(' ', $call['cmd']), $name);
            }
        }
    }
}
