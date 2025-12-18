<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullVerifyService implements VerifyServiceInterface
{
    public function verify(array $options): void
    {
        throw new LogicException('VerifyServiceInterface is not configured. Provide your own implementation that validates archives.');
    }
}
