<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use ArchiveUtil\ArchiveUtility;
use DbTools\Service\BackupService;
use DbTools\Service\BinaryResolver;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Whether a host option file is allowed to override the credentials we were
 * given.
 *
 * The bug this covers is quiet and expensive. MySQL reads ~/.my.cnf before it
 * reads anything we pass, and its precedence is command line > option file >
 * environment. We put the user on the command line and the password in
 * MYSQL_PWD, so on a machine with credentials in ~/.my.cnf the two came from
 * different places and the connection was refused. For backups that then
 * produced a valid archive of nothing.
 *
 * Both directions are pinned here. The flag has to appear when we hold
 * credentials, and it has to stay away when we do not — an installation that
 * relies on an option file on purpose, or on socket authentication, is not
 * having anything overridden and must keep working exactly as before.
 */
final class ClientDefaultsTest extends TestCase
{
    public function testAPasswordOfOurOwnShutsOutTheHostOptionFile(): void
    {
        $command = (new BinaryResolver(['mysqldump' => '/usr/bin/mysqldump']))
            ->client('mysqldump', 'a-real-password');

        self::assertSame(['/usr/bin/mysqldump', '--no-defaults'], $command);
    }

    /**
     * Position is not cosmetic. MySQL honours --no-defaults only as the first
     * argument and ignores it silently anywhere else, so a caller splicing it
     * in later would get a flag that reads as protection and is not.
     */
    public function testTheFlagComesFirstOrItDoesNothing(): void
    {
        $command = (new BinaryResolver(['mysql' => 'mysql']))->client('mysql', 'secret');

        self::assertSame('--no-defaults', $command[1] ?? null);
    }

    public function testNoPasswordLeavesTheOptionFileAlone(): void
    {
        $resolver = new BinaryResolver(['mysql' => 'mysql']);

        self::assertSame(['mysql'], $resolver->client('mysql', null));
        self::assertSame(['mysql'], $resolver->client('mysql', ''));
    }

    /**
     * The way back, for a host whose option file carries tuning rather than
     * credentials — max_allowed_packet and friends, which --no-defaults also
     * drops.
     */
    public function testTheEscapeHatchRestoresTheOldBehaviour(): void
    {
        $resolver = new BinaryResolver(['mysqldump' => 'mysqldump']);

        putenv('DBTOOLS_ALLOW_HOST_DEFAULTS=1');

        try {
            self::assertSame(['mysqldump'], $resolver->client('mysqldump', 'secret'));
        } finally {
            putenv('DBTOOLS_ALLOW_HOST_DEFAULTS');
        }
    }

    /** Set to something that means "no" is the same as not setting it. */
    public function testTheEscapeHatchIsNotOpenedByAFalseyValue(): void
    {
        $resolver = new BinaryResolver(['mysqldump' => 'mysqldump']);

        foreach (['0', 'false', 'no', ''] as $value) {
            putenv('DBTOOLS_ALLOW_HOST_DEFAULTS=' . $value);

            try {
                self::assertSame(
                    ['mysqldump', '--no-defaults'],
                    $resolver->client('mysqldump', 'secret'),
                    'DBTOOLS_ALLOW_HOST_DEFAULTS=' . $value,
                );
            } finally {
                putenv('DBTOOLS_ALLOW_HOST_DEFAULTS');
            }
        }
    }

    /** The binary itself is resolved the same way either way. */
    public function testMariadbNamesSurviveTheChange(): void
    {
        $resolver = new BinaryResolver(['mysqldump' => 'mariadb-dump']);

        self::assertSame(['mariadb-dump'], $resolver->client('mysqldump', null));
        self::assertSame(['mariadb-dump', '--no-defaults'], $resolver->client('mysqldump', 'p'));
        self::assertSame('mariadb-dump', $resolver->resolve('mysqldump'));
    }

    /**
     * And the service actually uses it.
     *
     * The unit tests above pin the helper. This pins the wiring, which is the
     * half that was wrong — the helper existing and one command builder still
     * calling resolve() directly would pass everything above and fix nothing.
     */
    public function testTheDumpCommandCarriesTheFlagWhenAPasswordIsConfigured(): void
    {
        self::assertSame('--no-defaults', $this->dumpCommand(['password' => 'dbsecret'])[1] ?? null);
    }

    public function testTheDumpCommandLeavesItOffWhenNoPasswordIsConfigured(): void
    {
        self::assertNotContains('--no-defaults', $this->dumpCommand([]));
    }

    /**
     * @param  array<string,mixed> $extra
     * @return list<string>
     */
    private function dumpCommand(array $extra): array
    {
        $dir = sys_get_temp_dir() . '/dbtools-defaults-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        ArchiveUtility::setMaxFileSize(null);

        $runner = new FakeProcessRunner();

        (new BackupService($runner))->backup([
            'database' => 'testdb',
            'host' => 'localhost',
            'user' => 'root',
            'output_dir' => $dir,
            // zip takes the dump-to-file path rather than the stream, which is
            // the one the fake runner records.
            'compression' => 'zip',
        ] + $extra);

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        foreach ($runner->invocations as $invocation) {
            if (in_array(basename((string) $invocation['cmd'][0]), ['mysqldump', 'mariadb-dump'], true)) {
                return array_values($invocation['cmd']);
            }
        }

        self::fail('no dump command was run');
    }
}
