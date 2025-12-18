<?php

declare(strict_types=1);

namespace DbTools\Command;

/**
 * Shared functionality for listing backup files.
 */
trait BackupListingTrait
{
    /**
     * Backup file extensions to search for.
     */
    private const BACKUP_EXTENSIONS = ['sql', 'sql.gz', 'sql.zst', 'sql.gpg', 'sql.gz.gpg', 'sql.zst.gpg', 'zip'];

    /**
     * Get backup files sorted by modification time (newest first).
     *
     * Uses glob() for efficient file discovery instead of iterating all files.
     *
     * @return list<array{name: string, path: string, mtime: int, size: int}>
     */
    private function getSortedBackups(string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            return [];
        }

        $outputDir = rtrim($outputDir, '/');
        $files = [];

        // Use glob for each extension - much faster than DirectoryIterator
        foreach (self::BACKUP_EXTENSIONS as $ext) {
            $pattern = $outputDir . '/*.' . $ext;
            $matches = glob($pattern, GLOB_NOSORT);

            if ($matches === false) {
                continue;
            }

            foreach ($matches as $path) {
                $filename = basename($path);

                // Skip metadata files
                if (str_ends_with($filename, '.meta.json')) {
                    continue;
                }

                $files[] = [
                    'name' => $filename,
                    'path' => $path,
                    'mtime' => filemtime($path) ?: 0,
                    'size' => filesize($path) ?: 0,
                ];
            }
        }

        // Sort by modification time, newest first
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $files;
    }

    /**
     * Format file size in human-readable format.
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
