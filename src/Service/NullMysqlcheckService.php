<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullMysqlcheckService implements MysqlcheckServiceInterface
{
    public function check(array $options): array
    {
        throw new LogicException('MysqlcheckServiceInterface is not configured. Provide your own implementation.');
    }

    public function analyze(array $options): array
    {
        throw new LogicException('MysqlcheckServiceInterface is not configured. Provide your own implementation.');
    }

    public function optimize(array $options): array
    {
        throw new LogicException('MysqlcheckServiceInterface is not configured. Provide your own implementation.');
    }

    public function repair(array $options): array
    {
        throw new LogicException('MysqlcheckServiceInterface is not configured. Provide your own implementation.');
    }
}
