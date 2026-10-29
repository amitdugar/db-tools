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
 *
 * gpg also needs a home directory it can write to, even for a passphrase. The
 * web server's account (www-data) has /var/www as its home, and root owns
 * /var/www, so gpg could not create ~/.gnupg there and every encrypted backup
 * run as www-data failed. When the account's own home cannot be used, gpg gets
 * a private one in the temp directory (see homeArgs).
 */
final class GpgPassphrase
{
    private ?string $path = null;

    /** @var list<string> */
    private array $homeArgs;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $passphrase,
        private readonly ?TempFileRegistry $registry = null,
        ?string $tempDir = null,
    ) {
        $this->homeArgs = self::homeArgs($tempDir);
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
     * The home directory gpg uses, when its own cannot be used.
     *
     * Nothing is added when GNUPGHOME is set, or when ~/.gnupg exists and is
     * writable, or can be created. Otherwise gpg gets dbtools-gnupg-<uid> in
     * the temp directory, made 0700. It is the same directory on every run, so
     * gpg starts one gpg-agent for it, not one per backup.
     *
     * The temp directory is shared with every user on the host. A directory of
     * that name that is a link, or belongs to another user, is refused rather
     * than used: whoever made it could read what gpg keeps there.
     *
     * @return list<string>
     */
    private static function homeArgs(?string $tempDir): array
    {
        $gnupgHome = getenv('GNUPGHOME');
        if ($gnupgHome !== false && $gnupgHome !== '') {
            return [];
        }
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = (posix_getpwuid(posix_geteuid()) ?: ['dir' => ''])['dir'];
        }
        $own = rtrim($home, '/') . '/.gnupg';
        if (is_dir($own) ? is_writable($own) : ($home !== '' && is_dir($home) && is_writable($home))) {
            return [];
        }

        $dir = self::directory($tempDir) . '/dbtools-gnupg-' . posix_geteuid();
        if (!is_link($dir) && !is_dir($dir)) {
            @mkdir($dir, 0700);
        }
        if (is_link($dir) || !is_dir($dir) || fileowner($dir) !== posix_geteuid()) {
            throw new RuntimeException(
                "gpg cannot create {$own}, and {$dir} is not a directory this account owns. Remove {$dir}."
            );
        }
        chmod($dir, 0700);

        return ['--homedir', $dir];
    }

    /**
     * The gpg arguments that carry the passphrase, after the home directory
     * gpg should use when its own cannot be used.
     *
     * @return list<string>
     */
    public function args(): array
    {
        return [
            ...$this->homeArgs,
            ...($this->path === null
                ? ['--passphrase', $this->passphrase]
                : ['--passphrase-file', $this->path]),
        ];
    }

    public function __destruct()
    {
        if ($this->path !== null) {
            @unlink($this->path);
            $this->registry?->release($this->path);
        }
    }
}
