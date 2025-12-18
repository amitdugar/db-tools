<?php

declare(strict_types=1);

namespace DbTools\Tests\Helper;

use Symfony\Component\Process\Process;

final class FakeProcess extends Process
{
    public function __construct(private readonly string $fakeOutput = '')
    {
        parent::__construct(['true']);
    }

    public function isSuccessful(): bool
    {
        return true;
    }

    public function getOutput(): string
    {
        return $this->fakeOutput;
    }

    public function getErrorOutput(): string
    {
        return '';
    }
}
