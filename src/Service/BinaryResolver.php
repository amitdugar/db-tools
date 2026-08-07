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

    /**
     * The binary, plus the flag that stops a host option file overriding us.
     *
     * MySQL's client tools read ~/.my.cnf, /etc/my.cnf and the rest before
     * they look at anything we pass, and the precedence between the three ways
     * of supplying a password is COMMAND LINE > OPTION FILE > ENVIRONMENT.
     * This tool puts the user on the command line and the password in
     * MYSQL_PWD, which is the right place for it — it stays out of `ps` — and
     * also the weakest of the three. A machine with credentials in ~/.my.cnf
     * therefore connects as the user we asked for with a password we did not,
     * and the command fails on access denied.
     *
     * That failure used to be invisible for backups: a shell pipeline reports
     * only its last stage, so `mysqldump | zstd > archive` wrote a valid
     * archive of nothing and exited 0. v3.2.3 fixed the reporting. This fixes
     * the cause.
     *
     * ONLY WHEN THERE IS A PASSWORD OF OUR OWN TO ASSERT. Passing
     * --no-defaults unconditionally would break the setup where an option file
     * is the intended source of credentials and the caller deliberately
     * configured none — socket authentication, or a ~/.my.cnf the operator
     * maintains on purpose. Nothing is overriding us there, so there is
     * nothing to stop.
     *
     * The flag has to come first. MySQL ignores --no-defaults in any other
     * position and says nothing, which is why this returns the binary with it
     * attached rather than leaving a caller to order the two correctly.
     *
     * ONE THING IS LOST WITH IT, and it is worth naming because it is not
     * credentials. An option file may also carry tuning — max_allowed_packet,
     * default-character-set, net_buffer_length — and --no-defaults drops those
     * too. A host that needs them can set DBTOOLS_ALLOW_HOST_DEFAULTS=1 and
     * get the old behaviour back, credentials included. That is an informed
     * choice rather than a default, because the failure it re-enables is the
     * silent one this exists to stop.
     *
     * @param  string       $tool     One of the keys of self::CANDIDATES.
     * @param  string|null  $password What the caller will put in MYSQL_PWD.
     * @return list<string>
     */
    public function client(string $tool, ?string $password): array
    {
        $binary = $this->resolve($tool);

        if ($password === null || $password === '' || $this->hostDefaultsAllowed()) {
            return [$binary];
        }

        return [$binary, '--no-defaults'];
    }

    private function hostDefaultsAllowed(): bool
    {
        $flag = getenv('DBTOOLS_ALLOW_HOST_DEFAULTS');

        return $flag !== false && !in_array(strtolower(trim($flag)), ['', '0', 'false', 'no'], true);
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
