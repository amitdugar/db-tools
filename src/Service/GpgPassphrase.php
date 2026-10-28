<?php

declare(strict_types=1);

namespace DbTools\Service;

use RuntimeException;

/**
 * A gpg passphrase handed to gpg in a file, not on its command line.
 *
 * With --passphrase the secret is an argument of the gpg process, and of the
 * shell that runs a backup pipeline. Any user on the host reads it with `ps`
 * for as long as the process runs, which is the whole backup. The file is
 * created 0600 in the temp directory and deleted when this object is
 * destroyed, so keep the object in scope until gpg has finished. It is also
 * registered for cleanup, so an interrupted run removes it too.
 *
 * Accepted tradeoff: a process killed outright (SIGKILL, the OOM killer, a
 * systemd stop timeout) runs no cleanup, and the file stays in the temp
 * directory until it is cleared. Only its owner and root can read it, where
 * the command line was readable by every user on the host.
 *
 * A caller that destroys data must create this before it does: a temp
 * directory that cannot take the file then fails the run while there is still
 * something to lose.
 *
 * gpg reads the first line of the file only. A passphrase with a line break
 * is still passed with --passphrase, as before: in a file it would be cut at
 * the break and no longer open the archives made with it.
 */
final class GpgPassphrase
{
    private ?string $path = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $passphrase,
        private readonly ?TempFileRegistry $registry = null,
        ?string $tempDir = null,
    ) {
        if (preg_match('/[\r\n]/', $passphrase) === 1) {
            return;
        }
        $dir = self::directory($tempDir);
        // tempnam() falls back to another directory, with only a notice, when it
        // cannot use the one asked for. The file goes where it was asked to, or
        // the run fails.
        $path = @tempnam($dir, 'dbtools-pass-');
        if ($path !== false && dirname($path) !== (realpath($dir) ?: $dir)) {
            @unlink($path);
            $path = false;
        }
        if ($path === false) {
            throw new RuntimeException("Unable to create a file for the gpg passphrase in {$dir}");
        }
        chmod($path, 0600);
        if (file_put_contents($path, $passphrase) !== strlen($passphrase)) {
            @unlink($path);
            throw new RuntimeException('Unable to write the gpg passphrase file');
        }
        $this->path = $path;
        $this->registry?->register($path);
    }

    /**
     * Where the file goes. A directory the operator chose (--temp-dir) is kept
     * to. Without one, the first writable of TMPDIR, the system temp directory
     * and /tmp: a stale TMPDIR inherited by cron must not stop the nightly
     * backup, and PHP's own fallback is that same TMPDIR when the process
     * started with it.
     */
    private static function directory(?string $tempDir): string
    {
        if ($tempDir !== null && $tempDir !== '') {
            return TempFileRegistry::resolveTempBase($tempDir);
        }
        $candidates = [TempFileRegistry::resolveTempBase(), sys_get_temp_dir(), '/tmp'];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_writable($candidate)) {
                return $candidate;
            }
        }
        return $candidates[0];
    }

    /**
     * The gpg arguments that carry the passphrase.
     *
     * @return list<string>
     */
    public function args(): array
    {
        return $this->path === null
            ? ['--passphrase', $this->passphrase]
            : ['--passphrase-file', $this->path];
    }

    public function __destruct()
    {
        if ($this->path !== null) {
            @unlink($this->path);
            $this->registry?->release($this->path);
        }
    }
}
