<?php

declare(strict_types=1);

namespace DbTools\Service;

/**
 * Resolves a logical client tool ("mysqldump") to the executable actually
 * installed on this machine ("mysqldump" or "mariadb-dump").
 *
 * MariaDB 11.0 renamed its client binaries to mariadb, mariadb-dump,
 * mariadb-binlog and so on, and deprecated the mysql* symlinks that used to
 * carry them. The symlinks still ship today, so hardcoding the old names works
 * for now and stops working on some future MariaDB release, on a host that
 * installed only the MariaDB-named tools, or in a slim container image.
 *
 * Note this is a *client-side* question, decided by what is on PATH. It is
 * independent of which server we end up connecting to — a MySQL client talks to
 * MariaDB and vice versa — so resolution never needs a live connection.
 */
final class BinaryResolver
{
    /**
     * Classic name first, MariaDB name as fallback. Order matters: on a host
     * with both, the classic name is what users expect to see in errors.
     *
     * @var array<string, list<string>>
     */
    private const CANDIDATES = [
        'mysql' => ['mysql', 'mariadb'],
        'mysqldump' => ['mysqldump', 'mariadb-dump'],
        'mysqlbinlog' => ['mysqlbinlog', 'mariadb-binlog'],
        'mysqlcheck' => ['mysqlcheck', 'mariadb-check'],
        'mysqladmin' => ['mysqladmin', 'mariadb-admin'],
    ];

    /** @var array<string, string> */
    private array $cache = [];

    /**
     * @param array<string, string> $overrides Logical name => explicit path, wins over PATH lookup.
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    /**
     * @param string $tool One of the keys of self::CANDIDATES.
     */
    public function resolve(string $tool): string
    {
        return $this->cache[$tool] ??= $this->lookUp($tool);
    }

    private function lookUp(string $tool): string
    {
        if (isset($this->overrides[$tool]) && $this->overrides[$tool] !== '') {
            return $this->overrides[$tool];
        }

        $env = getenv($this->envVar($tool));
        if ($env !== false && $env !== '') {
            return $env;
        }

        $candidates = self::CANDIDATES[$tool] ?? [$tool];

        foreach ($candidates as $candidate) {
            if ($this->isOnPath($candidate)) {
                return $candidate;
            }
        }

        // Nothing found. Return the classic name so the resulting "command not
        // found" names something the user recognises, rather than failing here
        // with an error that pre-empts the real one.
        return $candidates[0];
    }

    /**
     * Env override, e.g. DBTOOLS_MYSQLDUMP_BIN=/opt/mysql/bin/mysqldump
     */
    private function envVar(string $tool): string
    {
        return 'DBTOOLS_' . strtoupper($tool) . '_BIN';
    }

    private function isOnPath(string $name): bool
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $full = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($full) && is_executable($full)) {
                return true;
            }
        }

        return false;
    }
}
