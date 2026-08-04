<?php

declare(strict_types=1);

namespace DbTools\Service;

/**
 * Which server implementation we are talking to.
 *
 * MySQL and MariaDB diverged enough (collations, status tables, client binary
 * names) that several operations need to branch on this.
 */
enum ServerFlavor: string
{
    case MySQL = 'mysql';
    case MariaDB = 'mariadb';

    public function label(): string
    {
        return match ($this) {
            self::MySQL => 'MySQL',
            self::MariaDB => 'MariaDB',
        };
    }
}
