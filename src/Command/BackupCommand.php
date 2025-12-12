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

#[AsCommand(name: 'backup', description: 'Create a backup archive for the configured database')]
final class BackupCommand extends Command
{
    use SpinnerTrait;

    public function __construct(private readonly RuntimeInterface $runtime, private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('database', InputArgument::OPTIONAL, 'Database name (defaults to profile/env)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory where backup archives are written')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Optional note to embed in archive name')
            ->addOption('retention', null, InputOption::VALUE_OPTIONAL, 'Retention count (delete older backups beyond this)', null)
            ->addOption('compression', null, InputOption::VALUE_OPTIONAL, 'Compression backend (zstd|pigz|gzip|zip)')
            ->addOption('encryption-password', null, InputOption::VALUE_OPTIONAL, 'Password for encrypted archives')
            ->addOption('no-encrypt', null, InputOption::VALUE_NONE, 'Disable encryption (encryption is enabled by default)')
            ->addOption('label', null, InputOption::VALUE_OPTIONAL, 'Prefix label for filenames')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Backup all configured profiles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all')) {
            return $this->backupAll($input, $output);
        }

        return $this->backupSingle($input, $output);
    }

    private function backupAll(InputInterface $input, OutputInterface $output): int
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

        $failed = 0;
        $succeeded = 0;

        foreach ($profiles as $name => $profile) {
            $output->writeln(sprintf('Backing up <info>%s</info> [%s]...', $profile->database ?? $name, $name));

            $options = [
                'database' => (string) $profile->database,
                'host' => $profile->host ?? 'localhost',
                'port' => $profile->port,
                'user' => $profile->user,
                'password' => $profile->password,
                'output_dir' => $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $profile->outputDir,
                'note' => $input->getOption('note'),
                'retention' => $input->getOption('retention') ?? $profile->retention,
                'compression' => $input->getOption('compression') ?? $profile->compression,
                'encryption_password' => $input->getOption('encryption-password') ?? $profile->encryptionPassword,
                'encrypt' => !$input->getOption('no-encrypt'),
                'label' => $input->getOption('label') ?? $profile->label,
            ];

            try {
                $start = microtime(true);
                $tickCallback = $this->createSpinnerCallback($output, 'Dumping', $start);
                $archivePath = $this->runtime->backupService()->backup($options, $tickCallback);
                $this->clearSpinner($output);
                $elapsed = microtime(true) - $start;
                $output->writeln(sprintf('<info>✓</info> Backup created in %s: %s', $this->formatDuration($elapsed), basename($archivePath)));
                $output->writeln(sprintf('  <fg=gray>%s</>', $archivePath));
                $output->writeln('');
                $succeeded++;
            } catch (LogicException $e) {
                $this->clearSpinner($output);
                $output->writeln('<error>✗ ' . $e->getMessage() . '</error>');
                $output->writeln('');
                $failed++;
            }
        }

        $output->writeln(sprintf('Completed: %d succeeded, %d failed', $succeeded, $failed));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function backupSingle(InputInterface $input, OutputInterface $output): int
    {
        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;
        if (!$database) {
            $output->writeln('<error>Database name is required (argument, profile, or DBTOOLS_DATABASE env)</error>');
            return Command::FAILURE;
        }

        $options = [
            'database' => (string) $database,
            'host' => $this->resolveHost($input, $profile?->host),
            'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
            'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
            'password' => $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password),
            'output_dir' => $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $profile?->outputDir,
            'note' => $input->getOption('note'),
            'retention' => $input->getOption('retention') ?? $profile?->retention,
            'compression' => $input->getOption('compression') ?? $profile?->compression,
            'encryption_password' => $input->getOption('encryption-password') ?? $profile?->encryptionPassword,
            'encrypt' => !$input->getOption('no-encrypt'),
            'label' => $input->getOption('label') ?? $profile?->label,
        ];

        try {
            $output->writeln(sprintf('Backing up <info>%s</info>...', $database));
            $output->writeln('');

            $start = microtime(true);
            $tickCallback = $this->createSpinnerCallback($output, 'Dumping', $start);
            $archivePath = $this->runtime->backupService()->backup($options, $tickCallback);
            $this->clearSpinner($output);
            $elapsed = microtime(true) - $start;

            $output->writeln(sprintf('<info>✓</info> Backup created in %s: %s', $this->formatDuration($elapsed), basename($archivePath)));
            $output->writeln(sprintf('  <fg=gray>%s</>', $archivePath));
            return Command::SUCCESS;
        } catch (LogicException $e) {
            $this->clearSpinner($output);
            $output->writeln('<error>✗ ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
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
