<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;

/**
 * Tracks temporary files/directories and ensures cleanup on failure,
 * shutdown, or signal (SIGINT/SIGTERM/SIGHUP).
 */
final class TempFileRegistry
{
    /** @var list<string> */
    private array $paths = [];

    private bool $shutdownRegistered = false;
    private bool $signalsRegistered = false;

    /**
     * Register a temp file or directory for cleanup.
     */
    public function register(string $path): void
    {
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }

        $this->ensureShutdownHandler();
        $this->ensureSignalHandlers();
    }

    /**
     * Remove a path from the registry WITHOUT deleting it from disk.
     * Use when you've moved the file to its final location.
     */
    public function release(string $path): void
    {
        $this->paths = array_values(array_filter(
            $this->paths,
            static fn(string $p): bool => $p !== $path,
        ));
    }

    /**
     * Delete all registered paths from disk and clear the registry.
     */
    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            self::deletePath($path);
        }
        $this->paths = [];
    }

    /**
     * Return a copy of all currently registered paths.
     *
     * @return list<string>
     */
    public function registered(): array
    {
        return $this->paths;
    }

    /**
     * Create a unique temp directory under $baseDir and register it.
     */
    public function createTempDir(string $baseDir, string $prefix = 'dbtools-'): string
    {
        $dir = $baseDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create temp directory: {$dir}");
        }
        $this->register($dir);

        return $dir;
    }

    /**
     * Resolve the effective temp base directory.
     * Prefers explicit $override, then TMPDIR env, then sys_get_temp_dir().
     */
    public static function resolveTempBase(?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $envTmpDir = getenv('TMPDIR');
        if ($envTmpDir !== false && $envTmpDir !== '') {
            return $envTmpDir;
        }

        return sys_get_temp_dir();
    }

    private function ensureShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        register_shutdown_function(function (): void {
            $this->cleanup();
        });
        $this->shutdownRegistered = true;
    }

    private function ensureSignalHandlers(): void
    {
        if ($this->signalsRegistered) {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->cleanup();
            // Re-raise so the parent process sees the correct exit status
            pcntl_signal($signal, SIG_DFL);
            posix_kill(posix_getpid(), $signal);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGHUP, $handler);

        $this->signalsRegistered = true;
    }

    private static function deletePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::deletePath($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }
}
