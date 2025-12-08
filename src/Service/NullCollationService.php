<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullCollationService implements CollationServiceInterface
{
    public function changeCollation(array $options): void
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation that changes collation/charset.');
    }
}
