# Changelog

## v3.2.2

Fixes a data-loss bug in `restore`. Recommended for anyone using `--skip-safety-backup`.

### Fixed

- **`restore` dropped the target database before checking that the archive existed.** `recreateDatabase()` issues `DROP DATABASE IF EXISTS` / `CREATE DATABASE`, and the archive validation ran *after* it. A mistyped archive path therefore destroyed the database and only then reported `Archive not found`, leaving nothing to restore from. The same ordering applied to an encrypted archive with no usable passphrase. Both are now validated before anything destructive happens.

  With the default safety backup this was recoverable — a pre-restore archive had already been written. With `--skip-safety-backup` it was not. Verified against a live server: the same command that previously left the target database empty now leaves it untouched.

### Added

- Tests for the destructive paths, which were the thinnest-covered part of the codebase: that the safety backup runs by default and is skipped only when asked; that the `DROP` names the database the caller requested and not the one baked into the archive filename; and that validation precedes it. For PITR: that the recovery target and database filter reach every binlog, that binlogs are applied in order, that meta-file resolution joins the recorded directory, and that explicit binlogs override the meta file.

### Note

- `--pinentry-mode loopback` was considered for `gpg` and deliberately not added. Testing on GnuPG 2.2 and 2.4, headless and with no pinentry program available, showed the existing `--batch --passphrase` symmetric flow works without it.

## v3.2.1

Fixes `--version` reporting the wrong release, plus bugs surfaced by adding static analysis.

### Fixed

- **`db-tools --version` reported a stale release.** The version was a hardcoded literal in `ConsoleFactory` that was never bumped, so it still read `3.0.2` after both the 3.1.0 and 3.2.0 releases. Anyone checking on a server whether a fix had landed would have seen an older version than was actually installed and reasonably concluded the deploy had not reached that box. It is now read from Composer via `InstalledVersions`, so it always matches `composer show amitdugar/db-tools` and cannot drift again. A test pins it to that source.
- `import` and `restore` reported a nonsense duration when a failure occurred early: `$start` was assigned inside the `try`, so anything thrown before it left the variable undefined and the catch block computed elapsed time from nothing.
- `restore` and `import` could pass a null passphrase to `gpg` as a command argument. Unreachable through the current callers, which check first, but it now fails with a clear message rather than depending on a caller-side invariant.
- `verify` and `pitr-info` were constructed with a config argument their constructors do not accept. PHP silently discards extra arguments, so this was inert, but it hid the fact that neither command supports profiles.
- Replaced `preg_split('/\t/', …)` with `explode("\t", …)` in the row parsers. `preg_split` can return `false`, and the results were passed straight to `count()`, which is a fatal in PHP 8.
- `canUseFzf()` passed `shell_exec`'s result to `trim()` without handling the `false` it returns on failure.
- Removed dead code: a reference to `BackupOptions::$tempDir`, a property that has never existed, so the `isset()` guarding it was always false; an unused `ENV_MAPPINGS` constant duplicating `.env` handling that already works elsewhere; and a stale docblock on `createBackupDirectory()` documenting a different method's signature.

### Added

- PHPStan at level 8, running in CI on both PHP 8.4 and 8.5. Pre-existing cosmetic findings are captured in `phpstan-baseline.neon` so the build fails on anything new; the bugs listed above were fixed rather than baselined.

### Note

- `DbTestCommand::__construct()` no longer takes a `RuntimeInterface` as its first argument, and `SetupCommand::__construct()` no longer takes a `ProfilesConfig`; neither was used. These are internal command classes wired by `ConsoleFactory`, so this affects nothing that goes through the CLI or the service interfaces — but code constructing `DbTestCommand` directly needs to drop the first argument.

## v3.2.0

Fixes a silent backup-corruption bug and adds proper MariaDB support. No BC breaks.

### Fixed

- **Backups could silently succeed while producing an incomplete archive.** The dump pipeline was run as a shell string, so its exit status reflected only the last stage. A `mysqldump` that died — bad credentials, server gone, disk full mid-dump — was invisible, because the compressor downstream succeeded on the truncated input and exited 0. The backup was reported as successful and a valid-looking, incomplete archive was written. The pipeline now runs under `pipefail` so a failure in any stage fails the backup. If no pipefail-capable shell is found, a warning is logged.
- **Every backup and restore failure was masked by an unrelated fatal.** The cleanup paths in `BackupService::runPipelineToFile` and `ProcessRunner::runPipeline` called `proc_close()` on handles they had already closed. `@` does not suppress the resulting `TypeError`, so the real error — bad credentials, a `gpg` failure, a missing file — was replaced by an uncaught fatal and a stack trace. Notably this made `--encryption-password` appear broken when `gpg` failed for environmental reasons: no archive, no usable error.
- `backup` now creates its output directory if missing, matching `export`. Previously a non-existent `--output-dir` failed with an error naming neither the directory nor the cause.
- `collation` no longer picks `utf8mb4_0900_ai_ci` for MariaDB. The old check was `version_compare(VERSION(), '8.0.0', '>=')`, which is true for MariaDB 10.x and 11.x, but only MariaDB 11.4+ knows that collation — on 10.5, 10.6 and 10.11 every `ALTER` aborted with `ERROR 1273 Unknown collation`.
- `db:test` reported every MariaDB server as having `0s` uptime. It read `performance_schema.global_status`, and MariaDB ships with `performance_schema` off, so the value came back `NULL`. It now uses `SHOW GLOBAL STATUS`, which works on both.
- `db:test` no longer bypasses `ProcessRunner` with a raw `proc_open`, and drops a dead `sizeService()` call.

### Added

- MariaDB is now detected from `VERSION()` rather than inferred from the version number, including the `5.5.5-` compatibility prefix that MariaDB 10.x reports for old clients. `db:test` shows the detected server, and `collation` picks its default accordingly.
- Client binaries resolve automatically between the classic and MariaDB names (`mysqldump` / `mariadb-dump`, and so on), since MariaDB 11.0 renamed them and deprecated the `mysql*` symlinks. Override any of them with `DBTOOLS_MYSQL_BIN`, `DBTOOLS_MYSQLDUMP_BIN`, `DBTOOLS_MYSQLBINLOG_BIN` or `DBTOOLS_MYSQLCHECK_BIN`.
- `CollationService::serverVersion()` exposes the detected flavour and version. Deliberately **not** added to `CollationServiceInterface`, so third-party implementations keep working.

### Notes

- `CollationServiceInterface::isMysql8OrHigher()` is unchanged in signature and now returns `false` for MariaDB. Marked `@deprecated` in favour of `serverVersion()`.
- A MySQL 8.4 client cannot authenticate against a MariaDB server at all — they no longer share an auth plugin. Use the MariaDB client; the fallback above picks it up when installed.

## v3.1.0

- Honor an explicit `encryption_password` as a **fixed** backup passphrase. When a password is supplied (via `--encryption-password` or a profile's `encryption_password`), it is now used verbatim and **no random token is embedded in the filename**, so the archive is portable across machines whose database password differs. Restore opens it with the same `--encryption-password`.
- **Backward compatible:** with no explicit password the default scheme is unchanged — `DB_password` + a random token embedded in the filename, auto-derived on restore. The restore path is unchanged.

## v3.0.2

- CI now runs the PHPUnit test suite on PHP 8.4 and 8.5 (previously only validated and linted).
- Bump `actions/checkout` to v5 (clears the Node 20 deprecation warning). No library code changes.

## v3.0.1

- Release automation: tag pushes now publish to Packagist automatically via CI. No library code changes since 3.0.0.

## v3.0.0

Breaking: drops support for PHP 8.2 and 8.3, and moves to the Symfony 8 stack.

- **BC break:** require PHP 8.4+ (tested on 8.4 and 8.5). The `php` constraint is now `^8.4`, aligned with `symfony/console ^8.1` which requires PHP 8.4.1.
- **BC break:** upgrade `symfony/process` to `^8.1` and `amitdugar/archiveutil` to `^2.0`.
- Upgrade PHPUnit to `^13.1` (dev).
- Test against PHP 8.4 and 8.5 in CI (dropped 8.2).

## v2.1.0

- Stream backup/restore/import operations to eliminate temporary file disk usage.

## v1.0.0

- Ported backup/restore/verify/collation/export/clean/PITR/binlog purge services from legacy script into PSR-4 classes.
- Added real runtime wiring with Symfony Console commands for all operations.
- Integrated ArchiveUtility for compression/decompression and password-protected ZIP handling.
- Added PHPUnit test suite covering service flows and option parsing.
- Documented usage, requirements, licensing, and package metadata for Packagist release.
