<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullBinlogService implements BinlogServiceInterface
{
    public function purge(array $options): void
    {
        throw new LogicException('BinlogServiceInterface is not configured. Provide your own implementation.');
    }
}
