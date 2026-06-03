<?php

declare(strict_types=1);

namespace DbTools\Console;

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
    public static function create(RuntimeInterface $runtime, ?ProfilesConfig $config = null): Application
    {
        $app = new Application('db-tools', '3.0.1');

        $app->addCommand(new BackupCommand($runtime, $config));
        $app->addCommand(new RestoreCommand($runtime, $config));
        $app->addCommand(new VerifyCommand($runtime, $config));
        $app->addCommand(new CollationCommand($runtime, $config));
        $app->addCommand(new ExportCommand($runtime, $config));
        $app->addCommand(new CleanCommand($runtime, $config));
        $app->addCommand(new PitrInfoCommand($runtime, $config));
        $app->addCommand(new PitrRestoreCommand($runtime, $config));
        $app->addCommand(new BinlogPurgeCommand($runtime, $config));
        $app->addCommand(new SetupCommand($config));
        $app->addCommand(new ConfigListCommand($config));
        $app->addCommand(new ConfigShowCommand($config));
        $app->addCommand(new ListCommand($config));
        $app->addCommand(new SizeCommand($runtime, $config));
        $app->addCommand(new ImportCommand($runtime, $config));
        $app->addCommand(new MysqlcheckCommand($runtime, $config));
        $app->addCommand(new MaintainCommand($runtime, $config));
        $app->addCommand(new DbTestCommand($runtime, $config));

        return $app;
    }
}
