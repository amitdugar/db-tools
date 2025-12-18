<?php

declare(strict_types=1);

namespace DbTools\Config;

use RuntimeException;

final class ConfigLoader
{
    /**
     * Standard DB_* variable names used by Laravel, Symfony, etc.
     */
    private const ENV_MAPPINGS = [
        'DB_HOST' => 'host',
        'DB_PORT' => 'port',
        'DB_DATABASE' => 'database',
        'DB_USERNAME' => 'user',
        'DB_PASSWORD' => 'password',
        // Also check MySQL-style names
        'MYSQL_HOST' => 'host',
        'MYSQL_PORT' => 'port',
        'MYSQL_DATABASE' => 'database',
        'MYSQL_USER' => 'user',
        'MYSQL_PASSWORD' => 'password',
    ];

    /**
     * Load profiles from config files/env.
     */
    public static function load(string $cwd): ProfilesConfig
    {
        $profileName = getenv('DBTOOLS_PROFILE') ?: null;
        $paths = self::candidatePaths($cwd);

        $profiles = [];

        // 1. Check for DSN environment variable (highest priority quick setup)
        $dsn = getenv('DBTOOLS_DSN');
        if ($dsn !== false && $dsn !== '') {
            $dsnProfile = self::parseDsn($dsn);
            if ($dsnProfile !== null) {
                $profiles['default'] = $dsnProfile;
            }
        }

        // 2. Check for db-tools.php config files
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = require $path;
            if (!is_array($data)) {
                throw new RuntimeException("Config file must return an array: {$path}");
            }
            $profiles = self::parseProfiles($data, $cwd) + $profiles;
            // first readable file wins; stop scanning
            break;
        }

        // 3. Auto-detect .env file if no profiles found yet (zero-config for Laravel/Symfony)
        if ($profiles === []) {
            $profiles = self::loadProfilesFromEnvFile($cwd);
        }

        return new ProfilesConfig($profiles, $profileName);
    }

    /**
     * Try to load database config from .env file in the given directory.
     * Supports Laravel/Symfony DB_* and MySQL MYSQL_* variable conventions.
     *
     * Also supports profiles via prefixed variables:
     * - DB_HOST, DB_DATABASE, etc. → 'default' profile
     * - DB_PROD_HOST, DB_PROD_DATABASE, etc. → 'prod' profile
     * - DB_STAGING_HOST, DB_STAGING_DATABASE, etc. → 'staging' profile
     */
    public static function loadFromEnvFile(string $cwd): ?Profile
    {
        $profiles = self::loadProfilesFromEnvFile($cwd);
        return $profiles['default'] ?? null;
    }

    /**
     * Load all profiles from .env file.
     *
     * @return array<string, Profile>
     */
    public static function loadProfilesFromEnvFile(string $cwd): array
    {
        $envFile = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile)) {
            return [];
        }

        $vars = self::parseEnvFile($envFile);
        if ($vars === []) {
            return [];
        }

        // Group variables by profile prefix
        // DB_HOST → default, DB_PROD_HOST → prod, DB_STAGING_HOST → staging
        $grouped = self::groupEnvVarsByProfile($vars);

        // Extract DBTOOLS_* settings (shared across all profiles from .env)
        $outputDir = $vars['DBTOOLS_OUTPUT_DIR'] ?? $vars['DB_BACKUP_DIR'] ?? 'backups';
        // Resolve relative paths to absolute
        if (!str_starts_with($outputDir, '/')) {
            $outputDir = rtrim($cwd, '/') . '/' . ltrim($outputDir, './');
        }
        $retention = isset($vars['DBTOOLS_RETENTION']) ? (int) $vars['DBTOOLS_RETENTION'] : 7;
        $compression = $vars['DBTOOLS_COMPRESSION'] ?? 'zstd';

        $profiles = [];
        foreach ($grouped as $profileName => $profileVars) {
            $database = $profileVars['DATABASE'] ?? null;
            if ($database === null) {
                continue;
            }

            $profiles[$profileName] = new Profile(
                name: $profileName,
                host: $profileVars['HOST'] ?? 'localhost',
                port: isset($profileVars['PORT']) ? (int) $profileVars['PORT'] : 3306,
                database: $database,
                user: $profileVars['USERNAME'] ?? $profileVars['USER'] ?? null,
                password: $profileVars['PASSWORD'] ?? null,
                outputDir: $outputDir,
                retention: $retention,
                encryptionPassword: null,
                compression: $compression,
                label: $profileName,
            );
        }

        return $profiles;
    }

    /**
     * Group env variables by profile name.
     *
     * DB_HOST → ['default']['HOST']
     * DB_PROD_HOST → ['prod']['HOST']
     * MYSQL_DATABASE → ['default']['DATABASE']
     * MYSQL_PROD_DATABASE → ['prod']['DATABASE']
     *
     * @param array<string, string> $vars
     * @return array<string, array<string, string>>
     */
    private static function groupEnvVarsByProfile(array $vars): array
    {
        $grouped = [];

        foreach ($vars as $key => $value) {
            // Try DB_ prefix
            if (str_starts_with($key, 'DB_')) {
                $remainder = substr($key, 3); // Remove 'DB_'
                [$profileName, $field] = self::extractProfileAndField($remainder, ['HOST', 'PORT', 'DATABASE', 'USERNAME', 'USER', 'PASSWORD']);
                if ($field !== null) {
                    $grouped[$profileName][$field] = $value;
                }
                continue;
            }

            // Try MYSQL_ prefix
            if (str_starts_with($key, 'MYSQL_')) {
                $remainder = substr($key, 6); // Remove 'MYSQL_'
                [$profileName, $field] = self::extractProfileAndField($remainder, ['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD']);
                if ($field !== null) {
                    // Normalize USER to USERNAME for consistency
                    if ($field === 'USER') {
                        $field = 'USERNAME';
                    }
                    $grouped[$profileName][$field] = $value;
                }
            }
        }

        return $grouped;
    }

    /**
     * Extract profile name and field from a variable suffix.
     *
     * Examples:
     * - "HOST" → ['default', 'HOST']
     * - "PROD_HOST" → ['prod', 'HOST']
     * - "STAGING_DATABASE" → ['staging', 'DATABASE']
     *
     * @param list<string> $knownFields
     * @return array{string, string|null}
     */
    private static function extractProfileAndField(string $remainder, array $knownFields): array
    {
        // Check if it's a direct field (no profile prefix)
        foreach ($knownFields as $field) {
            if ($remainder === $field) {
                return ['default', $field];
            }
        }

        // Check for PROFILE_FIELD pattern
        foreach ($knownFields as $field) {
            $suffix = '_' . $field;
            if (str_ends_with($remainder, $suffix)) {
                $profileName = strtolower(substr($remainder, 0, -\strlen($suffix)));
                if ($profileName !== '') {
                    return [$profileName, $field];
                }
            }
        }

        return ['default', null];
    }

    /**
     * Parse a .env file into key-value pairs.
     * Simple parser - handles KEY=value, KEY="value", KEY='value', and comments.
     *
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $vars = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=value
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Only capture DB-related and DBTOOLS-related variables
            if (str_starts_with($key, 'DB_') || str_starts_with($key, 'MYSQL_') || str_starts_with($key, 'DBTOOLS_')) {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * Parse a DSN string into a Profile.
     * Format: mysql://user:password@host:port/database
     */
    public static function parseDsn(string $dsn): ?Profile
    {
        $parsed = parse_url($dsn);
        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        return new Profile(
            name: 'default',
            host: $parsed['host'],
            port: $parsed['port'] ?? 3306,
            database: isset($parsed['path']) ? ltrim($parsed['path'], '/') : null,
            user: $parsed['user'] ?? null,
            password: $parsed['pass'] ?? null,
            outputDir: null,
            retention: null,
            encryptionPassword: null,
            compression: null,
            label: null,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,Profile>
     */
    private static function parseProfiles(array $data, ?string $cwd = null): array
    {
        $profiles = [];
        foreach ($data as $name => $value) {
            if (!\is_array($value)) {
                continue;
            }
            // Resolve relative output_dir paths
            if ($cwd !== null && isset($value['output_dir']) && !str_starts_with((string) $value['output_dir'], '/')) {
                $value['output_dir'] = rtrim($cwd, '/') . '/' . ltrim((string) $value['output_dir'], './');
            }
            $profiles[(string) $name] = Profile::fromArray((string) $name, $value);
        }
        return $profiles;
    }

    /**
     * @return list<string>
     */
    private static function candidatePaths(string $cwd): array
    {
        $paths = [];

        $envPath = getenv('DBTOOLS_CONFIG');
        if ($envPath) {
            $paths[] = (string) $envPath;
        }

        $paths[] = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db-tools.local.php';
        $paths[] = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db-tools.php';

        $home = getenv('HOME');
        if ($home) {
            $paths[] = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.config/db-tools/profiles.php';
        }

        return $paths;
    }
}
