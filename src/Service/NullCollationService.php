<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullCollationService implements CollationServiceInterface
{
    public function changeCollation(array $options): void
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }

    public function convert(array $options): array
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }

    public function getTablesNeedingConversion(array $options): array
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }

    public function getColumnsNeedingConversion(array $options): array
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }

    public function isMysql8OrHigher(array $options): bool
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }

    public function getRecommendedCollation(array $options): string
    {
        throw new LogicException('CollationServiceInterface is not configured. Provide your own implementation.');
    }
}
