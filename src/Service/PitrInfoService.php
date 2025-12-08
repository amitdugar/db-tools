<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;

final class PitrInfoService implements PitrInfoServiceInterface
{
    /**
     * @param array<string,mixed> $options
     */
    public function info(array $options): array
    {
        if (!isset($options['meta'])) {
            throw new RuntimeException('Missing required option: meta');
        }

        $metaPath = (string) $options['meta'];
        if (!is_file($metaPath)) {
            throw new RuntimeException("Meta file not found: {$metaPath}");
        }

        $data = json_decode((string) file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid meta JSON');
        }

        $binlogs = $this->readBinlogs($options);
        $info = [
            'meta' => $data,
            'available_binlogs' => $binlogs,
        ];

        return $info;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,string>
     */
    private function readBinlogs(array $options): array
    {
        $binlogDir = isset($options['binlog_dir']) ? (string) $options['binlog_dir'] : null;
        if (!$binlogDir || !is_dir($binlogDir)) {
            return [];
        }

        $files = glob(rtrim($binlogDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mysql-bin.*') ?: [];
        sort($files);
        return array_map('basename', $files);
    }
}
