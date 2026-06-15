# Changelog

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
