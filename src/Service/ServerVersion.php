<?php

declare(strict_types=1);

namespace DbTools\Service;

/**
 * The parsed result of SELECT VERSION().
 *
 * Version strings are messier than they look:
 *   8.0.36                              MySQL
 *   5.7.44-log                          MySQL with a build suffix
 *   11.4.8-MariaDB-log                  MariaDB
 *   10.11.6-MariaDB-1:10.11.6+maria~deb12   MariaDB, Debian packaging
 *   5.5.5-10.4.11-MariaDB               MariaDB 10.4.11 (see below)
 *
 * That last form is the trap. MariaDB 10.x prefixes its version with "5.5.5-"
 * so that old clients, which assumed a leading "5.", keep working. Reading it
 * naively yields 5.5.5 and a decade-old feature set; ignoring the prefix but
 * comparing numerically yields 10.x and MySQL-only syntax. Both are wrong, so
 * the prefix is stripped explicitly before the number is parsed.
 */
final readonly class ServerVersion
{
    /** Available in every MySQL and every MariaDB release we support. */
    public const UNIVERSAL_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * MySQL 8.0's default. MariaDB only added it in 11.4 for compatibility;
     * 10.5, 10.6 and 10.11 — the LTS releases most deployments are on — reject
     * it with "ERROR 1273 Unknown collation".
     */
    public const MYSQL8_COLLATION = 'utf8mb4_0900_ai_ci';

    private function __construct(
        public ServerFlavor $flavor,
        public string $version,
        public string $raw,
    ) {
    }

    public static function parse(string $raw): self
    {
        $raw = trim($raw);

        // MariaDB is the only one that says so in the version string. Everything
        // else — including Percona and MySQL forks — behaves as MySQL here.
        $isMariaDb = stripos($raw, 'mariadb') !== false;

        $work = $raw;
        if ($isMariaDb && str_starts_with($work, '5.5.5-')) {
            $work = substr($work, 6);
        }

        $version = preg_match('/^(\d+(?:\.\d+){0,2})/', $work, $m) === 1 ? $m[1] : '0.0.0';

        return new self(
            $isMariaDb ? ServerFlavor::MariaDB : ServerFlavor::MySQL,
            $version,
            $raw,
        );
    }

    public function isMariaDb(): bool
    {
        return $this->flavor === ServerFlavor::MariaDB;
    }

    public function isAtLeast(string $version): bool
    {
        return version_compare($this->version, $version, '>=');
    }

    /**
     * The collation to use when the caller did not name one.
     *
     * Version number alone is not enough here: MariaDB 10.x and 11.x both
     * compare >= 8.0.0, but only MariaDB 11.4+ knows utf8mb4_0900_ai_ci. On
     * 10.5/10.6/10.11 the MySQL 8 default aborts every ALTER with ERROR 1273.
     *
     * MariaDB therefore gets the universal collation on all versions. It does
     * offer utf8mb4_uca1400_ai_ci from 10.10, but switching an existing schema
     * to it is a rewrite with a different sort order — not something to opt
     * users into implicitly. Anyone wanting uca1400 (or 0900 on 11.4+) passes
     * --collation explicitly.
     */
    public function recommendedCollation(): string
    {
        if ($this->isMariaDb()) {
            return self::UNIVERSAL_COLLATION;
        }

        return $this->isAtLeast('8.0.0')
            ? self::MYSQL8_COLLATION
            : self::UNIVERSAL_COLLATION;
    }

    public function describe(): string
    {
        return $this->flavor->label() . ' ' . $this->version;
    }
}
