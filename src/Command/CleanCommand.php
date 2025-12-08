<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use DbTools\Service\RuntimeInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'clean', description: 'Clean backup directory and optionally purge binlogs')]
final class CleanCommand extends Command
{
    public function __construct(private readonly RuntimeInterface $runtime, private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Backup directory to clean')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Keep N most recent backups (alias for --retention)')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'Retention count for backups')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Delete backups older than N days')
            ->addOption('purge-binlogs', null, InputOption::VALUE_OPTIONAL, 'Purge binlogs older than N days', null)
            ->addOption('database', null, InputOption::VALUE_OPTIONAL, 'Database name (for binlog purge)')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Database host')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Database port')
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Database user')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Database password')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clean backups for all configured profiles (keeps N per profile)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all')) {
            return $this->executeAll($input, $output);
        }

        return $this->executeSingle($input, $output);
    }

    private function executeAll(InputInterface $input, OutputInterface $output): int
    {
        if ($this->config === null) {
            $output->writeln('<error>No profiles configured</error>');
            return Command::FAILURE;
        }

        $profiles = $this->config->profiles();
        if ($profiles === []) {
            $output->writeln('<error>No profiles configured</error>');
            return Command::FAILURE;
        }

        // --keep is an alias for --retention (command-line overrides profile settings)
        $retentionOverride = $input->getOption('keep') ?? $input->getOption('retention');
        $daysOverride = $input->getOption('days') !== null ? (int) $input->getOption('days') : null;
        $hasErrors = false;

        foreach ($profiles as $name => $profile) {
            $output->writeln(\sprintf('Cleaning backups for <info>%s</info> [%s]...', $profile->database ?? $name, $name));

            $retention = $retentionOverride !== null ? (int) $retentionOverride : $profile->retention;

            $options = [
                'output_dir' => $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $profile->outputDir,
                'retention' => $retention,
                'days' => $daysOverride,
                'label' => $profile->label ?? $name,
                'binlog_days' => null,
            ];

            try {
                $this->runtime->cleanService()->clean($options);
                $output->writeln('  <info>Clean completed</info>');
            } catch (LogicException $e) {
                $output->writeln('  <error>' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }

            $output->writeln('');
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function executeSingle(InputInterface $input, OutputInterface $output): int
    {
        $profile = $this->config?->getProfile($this->resolveProfileName($input));

        // --keep is an alias for --retention
        $retention = $input->getOption('keep') ?? $input->getOption('retention') ?? $profile?->retention;

        $options = [
            'output_dir' => $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $profile?->outputDir,
            'retention' => $retention !== null ? (int) $retention : null,
            'days' => $input->getOption('days') !== null ? (int) $input->getOption('days') : null,
            'binlog_days' => $input->getOption('purge-binlogs'),
            'database' => $input->getOption('database') ?? getenv('DBTOOLS_DATABASE') ?? $profile?->database,
            'host' => $input->getOption('host') ?? getenv('DBTOOLS_HOST') ?? $profile?->host ?? 'localhost',
            'port' => $input->getOption('port') ? (int) $input->getOption('port') : $profile?->port,
            'user' => $input->getOption('user') ?? getenv('DBTOOLS_USER') ?? $profile?->user,
            'password' => $input->getOption('password') ?? getenv('DBTOOLS_PASSWORD') ?? $profile?->password,
        ];

        try {
            $this->runtime->cleanService()->clean($options);
            $output->writeln('<info>Clean completed</info>');
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
}
