# Changelog

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
