<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use DbTools\Service\RuntimeInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pitr-restore', description: 'Apply binlogs to reach a point-in-time')]
final class PitrRestoreCommand extends Command
{
    public function __construct(private readonly RuntimeInterface $runtime, private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('database', InputArgument::OPTIONAL, 'Database name (defaults to profile/env)')
            ->addOption('meta', null, InputOption::VALUE_OPTIONAL, 'Path to meta JSON describing binlogs')
            ->addOption('binlog', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Binlog file(s) to apply')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Stop datetime (YYYY-MM-DD HH:MM:SS)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;

        if (!$database) {
            $output->writeln('<error>Database name is required (argument, profile, or DBTOOLS_DATABASE env)</error>');
            return Command::FAILURE;
        }

        $options = [
            'database' => (string) $database,
            'meta' => $input->getOption('meta'),
            'binlogs' => $input->getOption('binlog'),
            'to' => $input->getOption('to'),
            'host' => $this->resolveHost($input, $profile?->host),
            'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
            'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
            'password' => $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password),
        ];

        try {
            $this->runtime->pitrRestoreService()->restore($options);
            $output->writeln('<info>PITR applied</info>');
            return Command::SUCCESS;
        } catch (LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function resolveProfileName(InputInterface $input): ?string
    {
        $val = $input->getOption('profile');
        if ($val !== null) {
            return (string) $val;
        }
        $env = getenv('DBTOOLS_PROFILE');
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
        return $this->config?->defaultProfile();
    }

    private function resolveInt(InputInterface $input, string $option, string $env, ?int $fallback): ?int
    {
        $val = $input->getOption($option);
        if ($val !== null && $val !== '') {
            return (int) $val;
        }
        $envVal = getenv($env);
        if ($envVal !== false && $envVal !== '') {
            return (int) $envVal;
        }
        return $fallback;
    }

    private function resolveString(InputInterface $input, string $option, string $env, ?string $fallback): ?string
    {
        $val = $input->getOption($option);
        if ($val !== null && $val !== '') {
            return (string) $val;
        }
        $envVal = getenv($env);
        if ($envVal !== false && $envVal !== '') {
            return (string) $envVal;
        }
        return $fallback;
    }

    private function resolveHost(InputInterface $input, ?string $fallback): string
    {
        $opt = $input->getOption('host');
        if ($opt !== null && $opt !== '') {
            return (string) $opt;
        }

        $env = getenv('DBTOOLS_HOST');
        if ($env !== false && $env !== '') {
            return (string) $env;
        }

        return $fallback ?? 'localhost';
    }
}
