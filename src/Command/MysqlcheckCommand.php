<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use DbTools\Service\RuntimeInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mysqlcheck', description: 'Run mysqlcheck operations (check, analyze, optimize, repair)')]
final class MysqlcheckCommand extends Command
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
            ->addOption('analyze', 'a', InputOption::VALUE_NONE, 'Run ANALYZE TABLE')
            ->addOption('optimize', 'o', InputOption::VALUE_NONE, 'Run OPTIMIZE TABLE')
            ->addOption('repair', 'r', InputOption::VALUE_NONE, 'Run REPAIR TABLE')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Run on all configured profiles');
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

        $operation = $this->getOperation($input);
        $operationLabel = $this->getOperationLabel($operation);
        $hasErrors = false;

        foreach ($profiles as $name => $profile) {
            $output->writeln(\sprintf('%s <info>%s</info> [%s]...', $operationLabel, $profile->database ?? $name, $name));
            $output->writeln('');

            $options = [
                'database' => (string) $profile->database,
                'host' => $profile->host ?? 'localhost',
                'port' => $profile->port,
                'user' => $profile->user,
                'password' => $profile->password,
            ];

            try {
                $service = $this->runtime->mysqlcheckService();
                $results = match ($operation) {
                    'analyze' => $service->analyze($options),
                    'optimize' => $service->optimize($options),
                    'repair' => $service->repair($options),
                    default => $service->check($options),
                };

                $this->displayResults($output, $results);

                foreach ($results as $result) {
                    if ($result['status'] === 'error') {
                        $hasErrors = true;
                        break;
                    }
                }
            } catch (RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }

            $output->writeln('');
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
        ];

        $operation = $this->getOperation($input);
        $operationLabel = $this->getOperationLabel($operation);

        try {
            $output->writeln(\sprintf('%s <info>%s</info>...', $operationLabel, $database));
            $output->writeln('');

            $service = $this->runtime->mysqlcheckService();
            $results = match ($operation) {
                'analyze' => $service->analyze($options),
                'optimize' => $service->optimize($options),
                'repair' => $service->repair($options),
                default => $service->check($options),
            };

            $this->displayResults($output, $results);

            // Check for errors
            $hasErrors = false;
            foreach ($results as $result) {
                if ($result['status'] === 'error') {
                    $hasErrors = true;
                    break;
                }
            }

            return $hasErrors ? Command::FAILURE : Command::SUCCESS;
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getOperation(InputInterface $input): string
    {
        if ($input->getOption('analyze')) {
            return 'analyze';
        }
        if ($input->getOption('optimize')) {
            return 'optimize';
        }
        if ($input->getOption('repair')) {
            return 'repair';
        }
        return 'check';
    }

    private function getOperationLabel(string $operation): string
    {
        return match ($operation) {
            'analyze' => 'Analyzing',
            'optimize' => 'Optimizing',
            'repair' => 'Repairing',
            default => 'Checking',
        };
    }

    /**
     * @param array<string, array{status: string, message: string}> $results
     */
    private function displayResults(OutputInterface $output, array $results): void
    {
        foreach ($results as $table => $result) {
            $statusIcon = match ($result['status']) {
                'ok' => '<info>OK</info>',
                'warning' => '<comment>WARN</comment>',
                'error' => '<error>ERROR</error>',
                default => '<fg=gray>???</>',
            };

            $output->writeln(sprintf('  %-50s %s', $table, $statusIcon));

            if ($result['status'] !== 'ok' && $result['message'] !== '') {
                $output->writeln(sprintf('    <fg=gray>%s</>', $result['message']));
            }
        }

        $output->writeln('');

        // Summary
        $total = \count($results);
        $ok = \count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $errors = \count(array_filter($results, fn($r) => $r['status'] === 'error'));
        $warnings = \count(array_filter($results, fn($r) => $r['status'] === 'warning'));

        $summary = sprintf('Total: %d tables', $total);
        if ($ok > 0) {
            $summary .= sprintf(', <info>%d OK</info>', $ok);
        }
        if ($warnings > 0) {
            $summary .= sprintf(', <comment>%d warnings</comment>', $warnings);
        }
        if ($errors > 0) {
            $summary .= sprintf(', <error>%d errors</error>', $errors);
        }

        $output->writeln($summary);
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
