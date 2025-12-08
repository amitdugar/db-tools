<?php

declare(strict_types=1);

namespace DbTools\Service;

interface VerifyServiceInterface
{
    /**
     * Verify an archive or backup directory.
     *
     * @param array<string,mixed> $options
     */
    public function verify(array $options): void;
}
