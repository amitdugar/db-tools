<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:show', description: 'Show the resolved configuration for a profile')]
final class ConfigShowCommand extends Command
{
    public function __construct(private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to show (or DBTOOLS_PROFILE)')
            ->addOption('validate', null, InputOption::VALUE_NONE, 'Validate the profile and show any issues');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profileName = $input->getOption('profile');
        if ($profileName === null) {
            $envProfile = getenv('DBTOOLS_PROFILE');
            if ($envProfile !== false && $envProfile !== '') {
                $profileName = $envProfile;
            }
        }

        $profile = $this->config?->getProfile($profileName);

        if ($profile === null) {
            $output->writeln('<error>No profile found.</error>');
            $output->writeln('');
            $output->writeln('Run <info>db-tools config:list</info> to see available profiles.');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Profile:</info> %s', $profile->name ?? 'default'));
        $output->writeln('');

        $data = $profile->toArray();
        unset($data['name']);

        foreach ($data as $key => $value) {
            $displayValue = $value ?? '<comment>(not set)</comment>';
            $output->writeln(sprintf('  <info>%-12s</info> %s', $key . ':', $displayValue));
        }

        // Show env var overrides that would apply
        $output->writeln('');
        $output->writeln('<info>Environment overrides:</info>');
        $envVars = [
            'DBTOOLS_HOST' => getenv('DBTOOLS_HOST'),
            'DBTOOLS_PORT' => getenv('DBTOOLS_PORT'),
            'DBTOOLS_USER' => getenv('DBTOOLS_USER'),
            'DBTOOLS_PASSWORD' => getenv('DBTOOLS_PASSWORD'),
            'DBTOOLS_DATABASE' => getenv('DBTOOLS_DATABASE'),
            'DBTOOLS_OUTPUT_DIR' => getenv('DBTOOLS_OUTPUT_DIR'),
        ];

        $hasOverrides = false;
        foreach ($envVars as $name => $value) {
            if ($value !== false && $value !== '') {
                $displayValue = str_contains($name, 'PASSWORD') ? '********' : $value;
                $output->writeln(sprintf('  <comment>%s</comment>=%s', $name, $displayValue));
                $hasOverrides = true;
            }
        }

        if (!$hasOverrides) {
            $output->writeln('  <comment>(none)</comment>');
        }

        // Validation
        if ($input->getOption('validate')) {
            $output->writeln('');
            $output->writeln('<info>Validation:</info>');

            $result = $profile->validate();

            if ($result['errors'] === [] && $result['warnings'] === []) {
                $output->writeln('  <info>✓</info> Profile is valid');
            } else {
                foreach ($result['errors'] as $error) {
                    $output->writeln(sprintf('  <error>✗</error> %s', $error));
                }
                foreach ($result['warnings'] as $warning) {
                    $output->writeln(sprintf('  <comment>!</comment> %s', $warning));
                }
            }

            if ($result['errors'] !== []) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
