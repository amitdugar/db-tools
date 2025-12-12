<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullRestoreService implements RestoreServiceInterface
{
    public function restore(array $options, ?callable $tickCallback = null): void
    {
        throw new LogicException('RestoreServiceInterface is not configured. Provide your own implementation that performs restores.');
    }
}
