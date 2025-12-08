# Changelog

## v1.0.0
- Ported backup/restore/verify/collation/export/clean/PITR/binlog purge services from legacy script into PSR-4 classes.
- Added real runtime wiring with Symfony Console commands for all operations.
- Integrated ArchiveUtility for compression/decompression and password-protected ZIP handling.
- Added PHPUnit test suite covering service flows and option parsing.
- Documented usage, requirements, licensing, and package metadata for Packagist release.
