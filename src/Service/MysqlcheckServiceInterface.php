<?php

declare(strict_types=1);

namespace DbTools\Service;

interface MysqlcheckServiceInterface
{
    /**
     * Run mysqlcheck on a database.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function check(array $options): array;

    /**
     * Run mysqlcheck --analyze on a database.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function analyze(array $options): array;

    /**
     * Run mysqlcheck --optimize on a database.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function optimize(array $options): array;

    /**
     * Run mysqlcheck --repair on a database.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    public function repair(array $options): array;
}
