<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullPitrInfoService implements PitrInfoServiceInterface
{
    public function info(array $options): array
    {
        throw new LogicException('PitrInfoServiceInterface is not configured. Provide your own implementation.');
    }
}
