<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'show', description: 'List available backup files')]
final class ListCommand extends Command
{
    use BackupListingTrait;

    public function __construct(private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to list backups from')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $this->config?->getProfile($this->resolveProfileName($input))?->outputDir;

        if ($outputDir === null || $outputDir === '') {
            $output->writeln('<error>No output directory configured. Use --output-dir or set output_dir in config.</error>');
            return Command::FAILURE;
        }

        if (!is_dir($outputDir)) {
            $output->writeln("<error>Directory does not exist: {$outputDir}</error>");
            return Command::FAILURE;
        }

        $backups = $this->getSortedBackups($outputDir);

        if ($backups === []) {
            $output->writeln("<comment>No backup files found in {$outputDir}</comment>");
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Backups in %s:</info>', $outputDir));
        $output->writeln('');

        $totalSize = 0;
        foreach ($backups as $i => $backup) {
            $size = $this->formatSize($backup['size']);
            $date = date('Y-m-d H:i:s', $backup['mtime']);
            $encrypted = str_ends_with($backup['name'], '.gpg') ? ' <fg=green>[encrypted]</>' : '';

            $output->writeln(sprintf(
                '  <comment>[%2d]</comment> %s%s',
                $i + 1,
                $backup['name'],
                $encrypted
            ));
            $output->writeln(sprintf(
                '       <fg=gray>%s  |  %s</>',
                $size,
                $date
            ));

            $totalSize += $backup['size'];
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Total:</info> %d backup(s), %s',
            \count($backups),
            $this->formatSize($totalSize)
        ));

        return Command::SUCCESS;
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
