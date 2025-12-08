<?php

declare(strict_types=1);

namespace DbTools\Service;

interface ExportServiceInterface
{
    /**
     * Export database to plain SQL file.
     *
     * @param array<string,mixed> $options
     * @return string Path to the export file
     */
    public function export(array $options): string;
}
