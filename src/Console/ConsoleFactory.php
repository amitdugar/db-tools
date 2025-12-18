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
        $app = new Application('db-tools', '2.0.0');

        $app->add(new BackupCommand($runtime, $config));
        $app->add(new RestoreCommand($runtime, $config));
        $app->add(new VerifyCommand($runtime, $config));
        $app->add(new CollationCommand($runtime, $config));
        $app->add(new ExportCommand($runtime, $config));
        $app->add(new CleanCommand($runtime, $config));
        $app->add(new PitrInfoCommand($runtime, $config));
        $app->add(new PitrRestoreCommand($runtime, $config));
        $app->add(new BinlogPurgeCommand($runtime, $config));
        $app->add(new SetupCommand($config));
        $app->add(new ConfigListCommand($config));
        $app->add(new ConfigShowCommand($config));
        $app->add(new ListCommand($config));
        $app->add(new SizeCommand($runtime, $config));
        $app->add(new ImportCommand($runtime, $config));
        $app->add(new MysqlcheckCommand($runtime, $config));
        $app->add(new MaintainCommand($runtime, $config));
        $app->add(new DbTestCommand($runtime, $config));

        return $app;
    }
}
