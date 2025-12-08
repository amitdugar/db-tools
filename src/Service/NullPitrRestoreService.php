<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullPitrRestoreService implements PitrRestoreServiceInterface
{
    public function restore(array $options): void
    {
        throw new LogicException('PitrRestoreServiceInterface is not configured. Provide your own implementation.');
    }
}
