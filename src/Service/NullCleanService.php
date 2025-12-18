<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullCleanService implements CleanServiceInterface
{
    public function clean(array $options): void
    {
        throw new LogicException('CleanServiceInterface is not configured. Provide your own implementation.');
    }
}
