<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\ServerFlavor;
use DbTools\Service\ServerVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ServerFlavor, string}>
     */
    public static function versionStrings(): iterable
    {
        yield 'mysql 8' => ['8.0.36', ServerFlavor::MySQL, '8.0.36'];
        yield 'mysql 8.4' => ['8.4.0', ServerFlavor::MySQL, '8.4.0'];
        yield 'mysql 5.7 with build suffix' => ['5.7.44-log', ServerFlavor::MySQL, '5.7.44'];
        yield 'percona reports as mysql' => ['8.0.35-27', ServerFlavor::MySQL, '8.0.35'];
        yield 'mariadb 11' => ['11.4.8-MariaDB-log', ServerFlavor::MariaDB, '11.4.8'];
        yield 'mariadb 10 bare' => ['10.4.11-MariaDB', ServerFlavor::MariaDB, '10.4.11'];
        yield 'mariadb debian packaging' => [
            '10.11.6-MariaDB-1:10.11.6+maria~deb12',
            ServerFlavor::MariaDB,
            '10.11.6',
        ];
        // MariaDB 10.x prefixes "5.5.5-" for old-client compatibility. Reading
        // it naively yields 5.5.5 and a decade-old feature set.
        yield 'mariadb 5.5.5 compatibility prefix' => ['5.5.5-10.4.11-MariaDB', ServerFlavor::MariaDB, '10.4.11'];
        yield 'unknown falls back to mysql' => ['', ServerFlavor::MySQL, '0.0.0'];
    }

    #[DataProvider('versionStrings')]
    public function testParsesFlavorAndVersion(string $raw, ServerFlavor $flavor, string $version): void
    {
        $parsed = ServerVersion::parse($raw);

        $this->assertSame($flavor, $parsed->flavor);
        $this->assertSame($version, $parsed->version);
        $this->assertSame($raw, $parsed->raw);
    }

    public function testMariaDbNeverGetsMysql8Collation(): void
    {
        // The bug this guards: version_compare('11.4.8', '8.0.0', '>=') is true,
        // so a naive check hands MariaDB utf8mb4_0900_ai_ci. Only MariaDB 11.4+
        // knows that collation; on 10.5/10.6/10.11 every ALTER aborts with
        // ERROR 1273 Unknown collation.
        foreach (['11.4.8-MariaDB-log', '10.4.11-MariaDB', '5.5.5-10.4.11-MariaDB'] as $raw) {
            $version = ServerVersion::parse($raw);

            $this->assertTrue($version->isMariaDb(), $raw);
            $this->assertSame(ServerVersion::UNIVERSAL_COLLATION, $version->recommendedCollation(), $raw);
            $this->assertNotSame(ServerVersion::MYSQL8_COLLATION, $version->recommendedCollation(), $raw);
        }
    }

    public function testMysql8GetsTheModernCollation(): void
    {
        $this->assertSame(
            ServerVersion::MYSQL8_COLLATION,
            ServerVersion::parse('8.0.36')->recommendedCollation()
        );
    }

    public function testMysql5GetsTheUniversalCollation(): void
    {
        $this->assertSame(
            ServerVersion::UNIVERSAL_COLLATION,
            ServerVersion::parse('5.7.44-log')->recommendedCollation()
        );
    }

    public function testUnreachableServerFallsBackToUniversalCollation(): void
    {
        $this->assertSame(
            ServerVersion::UNIVERSAL_COLLATION,
            ServerVersion::parse('')->recommendedCollation()
        );
    }

    public function testIsAtLeastComparesTheStrippedVersion(): void
    {
        $mariadb = ServerVersion::parse('5.5.5-10.4.11-MariaDB');

        $this->assertTrue($mariadb->isAtLeast('10.4.0'));
        $this->assertFalse($mariadb->isAtLeast('10.5.0'));
    }

    public function testDescribeNamesTheFlavor(): void
    {
        $this->assertSame('MariaDB 11.4.8', ServerVersion::parse('11.4.8-MariaDB-log')->describe());
        $this->assertSame('MySQL 8.0.36', ServerVersion::parse('8.0.36')->describe());
    }
}
