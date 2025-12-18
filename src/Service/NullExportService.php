<?php

declare(strict_types=1);

namespace DbTools\Service;

use LogicException;

final class NullExportService implements ExportServiceInterface
{
    public function export(array $options): string
    {
        throw new LogicException('ExportServiceInterface is not configured. Provide your own implementation.');
    }
}
