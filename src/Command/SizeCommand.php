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

#[AsCommand(name: 'size', description: 'Show database size and table breakdown')]
final class SizeCommand extends Command
{
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
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)')
            ->addOption('top', 't', InputOption::VALUE_REQUIRED, 'Show only top N tables by size', '20')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show size for all configured profiles');
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

        $top = (int) $input->getOption('top');
        $hasErrors = false;

        foreach ($profiles as $name => $profile) {
            $options = [
                'database' => (string) $profile->database,
                'host' => $profile->host ?? 'localhost',
                'port' => $profile->port,
                'user' => $profile->user,
                'password' => $profile->password,
                'top' => $top,
            ];

            try {
                $result = $this->runtime->sizeService()->getSize($options);
                $output->writeln(\sprintf('<comment>[%s]</comment>', $name));
                $this->displayResults($output, $result, $top);
            } catch (LogicException $e) {
                $output->writeln(\sprintf('<comment>[%s]</comment>', $name));
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                $output->writeln('');
                $hasErrors = true;
            }
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function executeSingle(InputInterface $input, OutputInterface $output): int
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
            'top' => (int) $input->getOption('top'),
        ];

        try {
            $result = $this->runtime->sizeService()->getSize($options);
            $this->displayResults($output, $result, $options['top']);
            return Command::SUCCESS;
        } catch (LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * @param array{database: string, total_size: int, data_size: int, index_size: int, tables: list<array{name: string, rows: int, data_size: int, index_size: int, total_size: int}>} $result
     */
    private function displayResults(OutputInterface $output, array $result, int $top): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<info>Database:</info> %s', $result['database']));
        $output->writeln('');

        $output->writeln(sprintf('  Total size:  <comment>%s</comment>', $this->formatSize($result['total_size'])));
        $output->writeln(sprintf('  Data size:   %s', $this->formatSize($result['data_size'])));
        $output->writeln(sprintf('  Index size:  %s', $this->formatSize($result['index_size'])));
        $output->writeln(sprintf('  Tables:      %d', \count($result['tables'])));

        if ($result['tables'] !== []) {
            $output->writeln('');
            $output->writeln(sprintf('<info>Top %d tables by size:</info>', min($top, \count($result['tables']))));
            $output->writeln('');

            // Header
            $output->writeln(sprintf(
                '  <comment>%-40s %12s %12s %12s %12s</comment>',
                'Table',
                'Rows',
                'Data',
                'Index',
                'Total'
            ));
            $output->writeln('  ' . str_repeat('-', 92));

            $shown = 0;
            foreach ($result['tables'] as $table) {
                if ($shown >= $top) {
                    break;
                }

                $name = $table['name'];
                if (\strlen($name) > 40) {
                    $name = substr($name, 0, 37) . '...';
                }

                $output->writeln(sprintf(
                    '  %-40s %12s %12s %12s %12s',
                    $name,
                    number_format($table['rows']),
                    $this->formatSize($table['data_size']),
                    $this->formatSize($table['index_size']),
                    $this->formatSize($table['total_size'])
                ));
                $shown++;
            }

            if (\count($result['tables']) > $top) {
                $output->writeln(sprintf('  <fg=gray>... and %d more tables</>', \count($result['tables']) - $top));
            }
        }

        $output->writeln('');
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $size, $units[$i]);
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
