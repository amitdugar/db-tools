<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\BinaryResolver;
use PHPUnit\Framework\TestCase;

final class BinaryResolverTest extends TestCase
{
    public function testExplicitOverrideWins(): void
    {
        $resolver = new BinaryResolver(['mysqldump' => '/opt/mariadb/bin/mariadb-dump']);

        $this->assertSame('/opt/mariadb/bin/mariadb-dump', $resolver->resolve('mysqldump'));
    }

    public function testEnvironmentOverrideIsHonoured(): void
    {
        putenv('DBTOOLS_MYSQLBINLOG_BIN=/custom/mariadb-binlog');

        try {
            $this->assertSame('/custom/mariadb-binlog', (new BinaryResolver())->resolve('mysqlbinlog'));
        } finally {
            putenv('DBTOOLS_MYSQLBINLOG_BIN');
        }
    }

    public function testResolvesToOneOfTheKnownCandidates(): void
    {
        // Whether the classic or the MariaDB name comes back depends on what is
        // installed, so assert membership rather than a fixed value.
        $expected = [
            'mysql' => ['mysql', 'mariadb'],
            'mysqldump' => ['mysqldump', 'mariadb-dump'],
            'mysqlbinlog' => ['mysqlbinlog', 'mariadb-binlog'],
            'mysqlcheck' => ['mysqlcheck', 'mariadb-check'],
            'mysqladmin' => ['mysqladmin', 'mariadb-admin'],
        ];

        $resolver = new BinaryResolver();

        foreach ($expected as $tool => $candidates) {
            $this->assertContains($resolver->resolve($tool), $candidates, $tool);
        }
    }

    public function testFallsBackToTheClassicNameWhenNothingIsInstalled(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent-dir-for-db-tools-test');

        try {
            $resolver = new BinaryResolver();

            // Not an exception: let the process fail with a "command not found"
            // naming a binary the user recognises.
            $this->assertSame('mysqldump', $resolver->resolve('mysqldump'));
            $this->assertSame('mysql', $resolver->resolve('mysql'));
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }
    }

    public function testUnknownToolResolvesToItself(): void
    {
        $this->assertSame('mysqlpump', (new BinaryResolver())->resolve('mysqlpump'));
    }

    public function testResolutionIsCached(): void
    {
        $resolver = new BinaryResolver();
        $first = $resolver->resolve('mysql');

        putenv('DBTOOLS_MYSQL_BIN=/should/be/ignored');

        try {
            $this->assertSame($first, $resolver->resolve('mysql'));
        } finally {
            putenv('DBTOOLS_MYSQL_BIN');
        }
    }
}
