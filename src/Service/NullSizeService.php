<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullSizeService implements SizeServiceInterface
{
    public function getSize(array $options): array
    {
        throw new LogicException('SizeServiceInterface is not configured. Provide your own implementation.');
    }
}
