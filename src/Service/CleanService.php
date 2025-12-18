<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;

final class CleanService implements CleanServiceInterface
{
    public function __construct(
        private readonly ?BinlogServiceInterface $binlogService = null
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function clean(array $options): void
    {
        if (!isset($options['output_dir'])) {
            throw new RuntimeException('Missing required option: output_dir');
        }

        $dir = rtrim((string) $options['output_dir'], DIRECTORY_SEPARATOR);
        $retention = isset($options['retention']) ? (int) $options['retention'] : null;
        $days = isset($options['days']) ? (int) $options['days'] : null;
        $purgeDays = isset($options['binlog_days']) ? (int) $options['binlog_days'] : null;
        $label = isset($options['label']) ? (string) $options['label'] : null;

        if (!is_dir($dir)) {
            return;
        }

        // Delete backups older than N days
        if ($days !== null && $days > 0) {
            $this->deleteOlderThan($dir, $days, $label);
        }

        // Apply retention count (keep N most recent)
        if ($retention !== null && $retention >= 0) {
            $this->applyRetention($dir, $retention, $label);
        }

        if ($this->binlogService && $purgeDays !== null) {
            $this->binlogService->purge(['days' => $purgeDays] + $options);
        }
    }

    private function applyRetention(string $dir, int $retention, ?string $label): void
    {
        $files = $this->getBackupFiles($dir, $label);
        natsort($files);
        $files = array_values(array_reverse($files));
        if ($retention < 1 || \count($files) <= $retention) {
            return;
        }

        foreach (array_slice($files, $retention) as $file) {
            $this->deleteBackupFile($file);
        }
    }

    private function deleteOlderThan(string $dir, int $days, ?string $label): void
    {
        $cutoff = time() - ($days * 86400);
        $files = $this->getBackupFiles($dir, $label);

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                $this->deleteBackupFile($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getBackupFiles(string $dir, ?string $label = null): array
    {
        $extensions = ['sql', 'sql.gz', 'sql.zst', 'sql.gpg', 'sql.gz.gpg', 'sql.zst.gpg', 'zip'];
        $files = [];

        // If label provided, use pattern: label-*
        $prefix = $label !== null ? $label . '-' : '';

        foreach ($extensions as $ext) {
            $matches = glob($dir . DIRECTORY_SEPARATOR . $prefix . '*.' . $ext);
            if ($matches !== false) {
                $files = array_merge($files, $matches);
            }
        }

        return array_values(array_unique($files));
    }

    private function deleteBackupFile(string $file): void
    {
        @unlink($file);

        // Also delete associated meta.json file
        $meta = preg_replace('/\.(sql|sql\.gz|sql\.zst|sql\.gpg|sql\.gz\.gpg|sql\.zst\.gpg|zip)$/', '.meta.json', $file);
        if ($meta !== null && $meta !== $file && is_file($meta)) {
            @unlink($meta);
        }
    }
}
