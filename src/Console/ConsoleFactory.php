<?php

declare(strict_types=1);

namespace DbTools\Console;

use Composer\InstalledVersions;
use DbTools\Command\BackupCommand;
use DbTools\Command\RestoreCommand;
use DbTools\Command\VerifyCommand;
use DbTools\Command\CollationCommand;
use DbTools\Command\ExportCommand;
use DbTools\Command\CleanCommand;
use DbTools\Command\PitrInfoCommand;
use DbTools\Command\PitrRestoreCommand;
use DbTools\Command\BinlogPurgeCommand;
use DbTools\Command\SetupCommand;
use DbTools\Command\ConfigListCommand;
use DbTools\Command\ConfigShowCommand;
use DbTools\Command\ListCommand;
use DbTools\Command\SizeCommand;
use DbTools\Command\ImportCommand;
use DbTools\Command\MysqlcheckCommand;
use DbTools\Command\MaintainCommand;
use DbTools\Command\DbTestCommand;
use DbTools\Config\ProfilesConfig;
use DbTools\Service\RuntimeInterface;
use Symfony\Component\Console\Application;

final class ConsoleFactory
{
    private const PACKAGE = 'amitdugar/db-tools';
    private const FALLBACK_VERSION = 'unknown';

    public static function create(RuntimeInterface $runtime, ?ProfilesConfig $config = null): Application
    {
        $app = new Application('db-tools', self::version());

        $app->addCommand(new BackupCommand($runtime, $config));
        $app->addCommand(new RestoreCommand($runtime, $config));
        $app->addCommand(new VerifyCommand($runtime));
        $app->addCommand(new CollationCommand($runtime, $config));
        $app->addCommand(new ExportCommand($runtime, $config));
        $app->addCommand(new CleanCommand($runtime, $config));
        $app->addCommand(new PitrInfoCommand($runtime));
        $app->addCommand(new PitrRestoreCommand($runtime, $config));
        $app->addCommand(new BinlogPurgeCommand($runtime, $config));
        $app->addCommand(new SetupCommand());
        $app->addCommand(new ConfigListCommand($config));
        $app->addCommand(new ConfigShowCommand($config));
        $app->addCommand(new ListCommand($config));
        $app->addCommand(new SizeCommand($runtime, $config));
        $app->addCommand(new ImportCommand($runtime, $config));
        $app->addCommand(new MysqlcheckCommand($runtime, $config));
        $app->addCommand(new MaintainCommand($runtime, $config));
        $app->addCommand(new DbTestCommand($config));

        return $app;
    }

    /**
     * The version Composer actually installed.
     *
     * This used to be a hardcoded literal, which silently went stale: it still
     * read 3.0.2 after both the 3.1.0 and 3.2.0 releases, so `db-tools
     * --version` on a server reported an older release than was installed —
     * exactly wrong for the one job that command has. Reading it from Composer
     * means it cannot drift again.
     */
    private static function version(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return self::FALLBACK_VERSION;
        }

        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::FALLBACK_VERSION;
        } catch (\OutOfBoundsException) {
            // Running from a checkout that Composer does not know as a package.
            return self::FALLBACK_VERSION;
        }
    }
}
