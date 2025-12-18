# db-tools

MySQL/MariaDB backup, restore, and recovery toolkit.

## Installation

```bash
composer require amitdugar/db-tools
```

## Quick Start

```bash
# See all available commands
vendor/bin/db-tools

# Get help for any command
vendor/bin/db-tools backup --help
vendor/bin/db-tools collation --help
```

## Setup

### If your project already has a `.env` file (Laravel, Symfony, etc.)

**You're done!** db-tools automatically reads `DB_*` variables from your `.env` file.

```bash
# Just works - no additional config needed
vendor/bin/db-tools backup --output-dir=/backups
```

If your `.env` doesn't have database variables yet, run setup:

```bash
vendor/bin/db-tools setup
```

This will prompt for credentials and add them to your `.env` file.

---

### If you're starting fresh

```bash
vendor/bin/db-tools setup
```

Choose where to save config:
- **`.env` file** (recommended) - adds `DB_HOST`, `DB_DATABASE`, etc.
- **Profile file** - saves to `~/.config/db-tools/profiles.php`

---

### Verify your setup

```bash
# Test database connectivity
vendor/bin/db-tools db:test

# Show current configuration
vendor/bin/db-tools config:show --validate
```

## Commands

### Backup & Restore

```bash
# Create compressed backup (zstd/pigz/gzip)
vendor/bin/db-tools backup --output-dir=/backups
vendor/bin/db-tools backup --output-dir=/backups --note=before-deploy
vendor/bin/db-tools backup --output-dir=/backups --encrypt          # GPG encrypted
vendor/bin/db-tools backup --output-dir=/backups --compression=zstd # specific compression

# Restore from backup
vendor/bin/db-tools restore /backups/mydb-20240501-0100.sql.zst
vendor/bin/db-tools restore /backups/mydb.sql.zst.gpg              # auto-decrypts

# Import SQL file (supports .sql, .gz, .zst, .zip, .gpg)
vendor/bin/db-tools import /path/to/dump.sql.zst
vendor/bin/db-tools import /path/to/dump.sql.gpg -e secretpassword

# Verify archive integrity
vendor/bin/db-tools verify /backups/mydb-20240501-0100.sql.zst

# Export plain SQL (no compression)
vendor/bin/db-tools export mydb /backups/mydb.sql
```

### Backup Management

```bash
# List backup files in directory
vendor/bin/db-tools show --output-dir=/backups

# Show database/table sizes
vendor/bin/db-tools size                           # all databases
vendor/bin/db-tools size mydb                      # specific database
vendor/bin/db-tools size mydb --tables             # show table breakdown

# Clean old backups (keep last N)
vendor/bin/db-tools clean --output-dir=/backups --retention=7

# Clean backups older than N days
vendor/bin/db-tools clean --output-dir=/backups --days=30
```

### Database Maintenance

```bash
# Fix collation (convert to utf8mb4)
vendor/bin/db-tools collation mydb                           # convert all tables
vendor/bin/db-tools collation mydb --dry-run                 # preview changes
vendor/bin/db-tools collation mydb --table=users             # specific table only
vendor/bin/db-tools collation mydb --skip-columns            # tables only, skip columns
vendor/bin/db-tools collation mydb --collation=utf8mb4_unicode_ci  # specific collation

# Run mysqlcheck operations
vendor/bin/db-tools mysqlcheck mydb                          # check tables
vendor/bin/db-tools mysqlcheck mydb --analyze                # update index statistics
vendor/bin/db-tools mysqlcheck mydb --optimize               # optimize tables
vendor/bin/db-tools mysqlcheck mydb --repair                 # repair tables

# Test database connectivity
vendor/bin/db-tools db:test
```

### Point-in-Time Recovery

```bash
# View available recovery points
vendor/bin/db-tools pitr-info --meta=/backups/mydb.meta.json

# Restore to specific point in time
vendor/bin/db-tools pitr-restore --meta=/backups/mydb.meta.json --to="2024-05-10 12:00:00"

# Purge old binary logs
vendor/bin/db-tools purge-binlogs --before="2024-05-01"
```

### Configuration

```bash
# List available profiles
vendor/bin/db-tools config:list

# Show current configuration
vendor/bin/db-tools config:show
vendor/bin/db-tools config:show --validate    # test connection

# Interactive setup
vendor/bin/db-tools setup

# Non-interactive setup (for deploy scripts)
vendor/bin/db-tools setup --no-prompt --database=mydb --user=root --password=secret
vendor/bin/db-tools setup --no-prompt --database=mydb --user=root -o config  # output to db-tools.php
```

## Profiles (Multiple Databases)

If you manage multiple databases, use **profiles**. Each profile is a named database configuration that you can switch between.

### When to use profiles

- You have separate databases (e.g., main app + analytics)
- You need different backup settings per database
- You want to manage multiple projects from one machine

### Quick setup with profiles

```bash
# Add your main database (uses "default" profile)
vendor/bin/db-tools setup --no-prompt --database=myapp --user=root --password=secret

# Add an analytics database as a separate profile
vendor/bin/db-tools setup --no-prompt --profile=analytics --database=analytics_db --user=root --password=secret

# Add a legacy database
vendor/bin/db-tools setup --no-prompt --profile=legacy --database=old_app --user=root --password=secret
```

### Using profiles

```bash
# Commands use "default" profile by default
vendor/bin/db-tools backup --output-dir=/backups

# Specify a profile with --profile
vendor/bin/db-tools backup --profile=analytics --output-dir=/backups
vendor/bin/db-tools restore --profile=legacy /backups/old_app.sql.zst
vendor/bin/db-tools size --profile=analytics

# List all configured profiles
vendor/bin/db-tools config:list
```

### How profiles are stored

**In `.env` file** (default) - profiles use prefixed variable names:

```bash
# Default profile
DB_HOST=localhost
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret

# Analytics profile (note the _ANALYTICS_ prefix)
DB_ANALYTICS_HOST=localhost
DB_ANALYTICS_DATABASE=analytics_db
DB_ANALYTICS_USERNAME=root
DB_ANALYTICS_PASSWORD=secret
```

**In `db-tools.php`** - profiles are array keys:

```php
<?php
return [
    'default' => [
        'host' => 'localhost',
        'database' => 'myapp',
        'user' => 'root',
        'password' => 'secret',
    ],
    'analytics' => [
        'host' => 'localhost',
        'database' => 'analytics_db',
        'user' => 'root',
        'password' => 'secret',
    ],
];
```

## Cron Jobs

db-tools is designed for cron. Just `cd` to your project and run:

```bash
# Nightly backup at 2am
0 2 * * * cd /var/www/myapp && vendor/bin/db-tools backup --output-dir=/backups --retention=7

# Weekly full backup with note
0 3 * * 0 cd /var/www/myapp && vendor/bin/db-tools backup --output-dir=/backups --note=weekly

# Daily cleanup - keep last 7 backups
0 4 * * * cd /var/www/myapp && vendor/bin/db-tools clean --output-dir=/backups --retention=7

# Monthly - delete backups older than 30 days
0 5 1 * * cd /var/www/myapp && vendor/bin/db-tools clean --output-dir=/backups --days=30
```

No environment setup needed in cron - db-tools reads directly from your project's `.env` file.

## Non-Interactive Setup

For deploy scripts and CI/CD pipelines, use `--no-prompt` mode:

```bash
# Basic - adds DB variables to .env file
vendor/bin/db-tools setup --no-prompt --database=mydb --user=root --password=secret

# With all options
vendor/bin/db-tools setup --no-prompt \
  --database=mydb \
  --host=localhost \
  --port=3306 \
  --user=root \
  --password=secret \
  --output-dir=./backups \
  --retention=7

# Output to db-tools.php instead of .env
vendor/bin/db-tools setup --no-prompt --database=mydb --user=root -o config

# Output to user profile (~/.config/db-tools/profiles.php)
vendor/bin/db-tools setup --no-prompt --database=mydb --user=root -o profile
```

| Option | Default | Description |
|--------|---------|-------------|
| `--no-prompt` | | Run without prompts (required) |
| `-o, --output` | `env` | Output format: `env`, `config`, or `profile` |
| `-p, --profile` | `default` | Profile name for multiple databases |
| `--database` | | Database name (required) |
| `--host` | `localhost` | Database host |
| `--port` | `3306` | Database port |
| `--user` | `root` | Database user |
| `--password` | | Database password |
| `--output-dir` | `./backups` | Backup directory |
| `--retention` | `7` | Retention count |

## Config Files

Most projects don't need a config file - db-tools reads your `.env` automatically. Use config files when you need:
- Multiple databases or profiles
- Custom `output_dir`, `retention`, or other options
- Non-standard environment variable names

### `db-tools.php` (commit to repo)

```php
<?php
// db-tools.php - shared project config
return [
    'default' => [
        'host'       => $_ENV['DB_HOST'] ?? 'localhost',
        'port'       => (int) ($_ENV['DB_PORT'] ?? 3306),
        'database'   => $_ENV['DB_DATABASE'],
        'user'       => $_ENV['DB_USERNAME'],
        'password'   => $_ENV['DB_PASSWORD'],
        'output_dir' => __DIR__ . '/backups',
        'retention'  => 7,
        'compression' => 'zstd',
    ],
];
```

### `db-tools.local.php` (gitignore, local overrides)

```php
<?php
// db-tools.local.php - local dev overrides (add to .gitignore)
return [
    'default' => [
        'host'     => '127.0.0.1',
        'database' => 'myapp_dev',
        'user'     => 'root',
        'password' => 'localpass',
    ],
];
```

## Configuration Reference

### Auto-detected environment variables

db-tools automatically reads these from your `.env` file:

```
DB_HOST        (or MYSQL_HOST)
DB_PORT        (or MYSQL_PORT)
DB_DATABASE    (or MYSQL_DATABASE)
DB_USERNAME    (or MYSQL_USER)
DB_PASSWORD    (or MYSQL_PASSWORD)
```

### Config file locations (first found wins)

1. `DBTOOLS_CONFIG` env var
2. `db-tools.local.php` (project root)
3. `db-tools.php` (project root)
4. `~/.config/db-tools/profiles.php`

### Profile options

| Option | Type | Description |
|--------|------|-------------|
| `host` | string | Database host (default: localhost) |
| `port` | int | Database port (default: 3306) |
| `database` | string | Database name |
| `user` | string | Database user |
| `password` | string | Database password |
| `output_dir` | string | Backup directory |
| `retention` | int | Keep last N backups |
| `compression` | string | zstd, pigz, gzip, or zip |
| `encryption_password` | string | Encrypt backups (AES-256) |
| `label` | string | Filename prefix |

### Command-line overrides

Any option can be overridden via CLI or environment:

```bash
# CLI flags
vendor/bin/db-tools backup --host=otherhost --database=otherdb

# Environment variables (prefix with DBTOOLS_)
DBTOOLS_HOST=otherhost DBTOOLS_DATABASE=otherdb vendor/bin/db-tools backup
```

## Encrypted Backups

All compression backends support encryption using GPG with AES-256. There are two ways to encrypt:

### Auto-generated password (recommended)

Use `--encrypt` to automatically generate a secure password. The password is derived from your database password plus a random string embedded in the filename:

```bash
# Create encrypted backup (password auto-generated)
vendor/bin/db-tools backup --encrypt --output-dir=/backups

# Output: mydb-20240501-0100-x7Kp9mQrAbCd1234XyZ.sql.zst.gpg
#                            └──────────────────┘
#                            32-char random string

# Restore - password is derived from DB_PASSWORD + random string from filename
vendor/bin/db-tools restore /backups/mydb-20240501-0100-x7Kp9mQrAbCd1234XyZ.sql.zst.gpg
```

**How it works:** The encryption password is `DB_PASSWORD + randomString`. Since the random string is embedded in the filename, restore automatically derives the correct password when you provide your database credentials.

### Manual password

Use `--encryption-password` to specify your own password:

```bash
# Create encrypted backup with custom password
vendor/bin/db-tools backup --encryption-password=secret --output-dir=/backups

# Restore encrypted backup
vendor/bin/db-tools restore /backups/mydb.sql.zst.gpg --encryption-password=secret

# Verify encrypted backup
vendor/bin/db-tools verify /backups/mydb.sql.zst.gpg --password=secret
```

Store the password securely (e.g., in environment variable):

```php
// db-tools.php
return [
    'default' => [
        // ...
        'encryption_password' => getenv('BACKUP_ENCRYPTION_KEY'),
    ],
];
```

### How encryption works

- **ZIP** (`--compression=zip`): Native ZIP encryption (AES-256)
- **Other backends** (zstd, gzip, pigz): File is compressed, then encrypted with GPG symmetric AES-256 → `.sql.zst.gpg`

## Collation Conversion

The `collation` command converts database tables and columns to utf8mb4 with automatic MySQL version detection:

- **MySQL 8+**: Uses `utf8mb4_0900_ai_ci`
- **MySQL 5.x/MariaDB**: Uses `utf8mb4_unicode_ci`

```bash
# Preview what would be converted (no changes)
vendor/bin/db-tools collation mydb --dry-run

# Convert all tables and columns
vendor/bin/db-tools collation mydb

# Convert specific table only
vendor/bin/db-tools collation mydb --table=users

# Convert tables only, skip individual column conversion
vendor/bin/db-tools collation mydb --skip-columns

# Specify custom collation
vendor/bin/db-tools collation mydb --collation=utf8mb4_general_ci

# Verbose output (shows column-level details)
vendor/bin/db-tools collation mydb -v
```

The command preserves all column attributes: NULL/NOT NULL, DEFAULT values, AUTO_INCREMENT, COMMENT, etc.

## Import Formats

The `import` command handles various file formats:

| Extension | Description |
|-----------|-------------|
| `.sql` | Plain SQL file |
| `.sql.gz` | Gzip compressed |
| `.sql.zst` | Zstandard compressed |
| `.zip` | ZIP archive (with optional password) |
| `.sql.gpg` | GPG encrypted |
| `.sql.zst.gpg` | Compressed + encrypted |

```bash
# Import plain SQL
vendor/bin/db-tools import /path/to/dump.sql

# Import compressed
vendor/bin/db-tools import /path/to/dump.sql.zst

# Import encrypted (auto-derives password from filename if possible)
vendor/bin/db-tools import /path/to/dump.sql.gpg

# Import encrypted with explicit password
vendor/bin/db-tools import /path/to/dump.sql.gpg -e mypassword
```

## Requirements

- PHP 8.2+
- `mysqldump`, `mysql` CLI tools
- Compression: `zstd` (preferred), `pigz`, or `gzip`
- Encryption: `gpg` (for non-ZIP encrypted backups)

## License

MIT
