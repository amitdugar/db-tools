<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use DbTools\Service\RuntimeInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'maintain', description: 'Run full database maintenance (mysqlcheck + purge binlogs)')]
final class MaintainCommand extends Command
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
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Purge binlogs older than N days', '7')
            ->addOption('optimize', 'o', InputOption::VALUE_NONE, 'Also run OPTIMIZE TABLE (slower, reclaims disk space)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Run on all configured profiles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        $runOptimize = (bool) $input->getOption('optimize');
        $hasErrors = false;

        $stepCount = $runOptimize ? 3 : 2;
        $currentStep = 0;

        $output->writeln('===========================================');
        $output->writeln('  DATABASE MAINTENANCE');
        $output->writeln('===========================================');
        $output->writeln('');

        // Step 1: Analyze tables
        $currentStep++;
        $output->writeln(\sprintf('Step %d/%d: Analyzing tables (updating statistics)...', $currentStep, $stepCount));
        $output->writeln('');

        if ($input->getOption('all')) {
            $hasErrors = $this->runMysqlcheckAll($input, $output, 'analyze') || $hasErrors;
        } else {
            $hasErrors = $this->runMysqlcheckSingle($input, $output, 'analyze') || $hasErrors;
        }

        $output->writeln('');

        // Step 2 (optional): Optimize tables
        if ($runOptimize) {
            $currentStep++;
            $output->writeln(\sprintf('Step %d/%d: Optimizing tables (defragmenting, this may take a while)...', $currentStep, $stepCount));
            $output->writeln('');

            if ($input->getOption('all')) {
                $hasErrors = $this->runMysqlcheckAll($input, $output, 'optimize') || $hasErrors;
            } else {
                $hasErrors = $this->runMysqlcheckSingle($input, $output, 'optimize') || $hasErrors;
            }

            $output->writeln('');
        }

        $output->writeln('');

        // Final step: Purge Binary Logs (runs once, uses first/default profile for connection)
        $currentStep++;
        $output->writeln(\sprintf('Step %d/%d: Cleaning up binary logs (older than %d days)...', $currentStep, $stepCount, $days));

        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;

        if ($database) {
            $binlogOptions = [
                'database' => (string) $database,
                'days' => $days,
                'host' => $this->resolveHost($input, $profile?->host),
                'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
                'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
                'password' => $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password),
            ];

            try {
                $this->runtime->binlogService()->purge($binlogOptions);
                $output->writeln('  <info>Binary log cleanup completed</info>');
            } catch (LogicException $e) {
                $output->writeln('  <error>Binary log cleanup failed: ' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }
        } else {
            $output->writeln('  <comment>Skipped (no database specified)</comment>');
        }

        $output->writeln('');
        $output->writeln('===========================================');
        $output->writeln('  MAINTENANCE COMPLETE');
        $output->writeln('===========================================');

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function runMysqlcheckAll(InputInterface $input, OutputInterface $output, string $operation): bool
    {
        if ($this->config === null) {
            $output->writeln('<error>No profiles configured</error>');
            return true;
        }

        $profiles = $this->config->profiles();
        if ($profiles === []) {
            $output->writeln('<error>No profiles configured</error>');
            return true;
        }

        $hasErrors = false;
        $operationLabel = $this->getOperationLabel($operation);

        foreach ($profiles as $name => $profile) {
            $output->writeln(\sprintf('%s <info>%s</info> [%s]...', $operationLabel, $profile->database ?? $name, $name));

            $options = [
                'database' => (string) $profile->database,
                'host' => $profile->host ?? 'localhost',
                'port' => $profile->port,
                'user' => $profile->user,
                'password' => $profile->password,
            ];

            try {
                $results = $this->runOperation($operation, $options);
                $this->displayMysqlcheckSummary($output, $results, $operation);
            } catch (RuntimeException $e) {
                $output->writeln('  <error>Failed: ' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }

            $output->writeln('');
        }

        return $hasErrors;
    }

    private function runMysqlcheckSingle(InputInterface $input, OutputInterface $output, string $operation): bool
    {
        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;

        if (!$database) {
            $output->writeln('<error>Database name is required (argument, profile, or DBTOOLS_DATABASE env)</error>');
            return true;
        }

        $options = [
            'database' => (string) $database,
            'host' => $this->resolveHost($input, $profile?->host),
            'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
            'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
            'password' => $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password),
        ];

        $operationLabel = $this->getOperationLabel($operation);
        $output->writeln(\sprintf('%s <info>%s</info>...', $operationLabel, $database));

        try {
            $results = $this->runOperation($operation, $options);
            $this->displayMysqlcheckSummary($output, $results, $operation);
            return false;
        } catch (RuntimeException $e) {
            $output->writeln('  <error>Failed: ' . $e->getMessage() . '</error>');
            return true;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, message: string}>
     */
    private function runOperation(string $operation, array $options): array
    {
        $service = $this->runtime->mysqlcheckService();
        return match ($operation) {
            'analyze' => $service->analyze($options),
            'optimize' => $service->optimize($options),
            default => $service->check($options),
        };
    }

    private function getOperationLabel(string $operation): string
    {
        return match ($operation) {
            'analyze' => 'Analyzing',
            'optimize' => 'Optimizing',
            default => 'Checking',
        };
    }

    /**
     * @param array<string, array{status: string, message: string}> $results
     */
    private function displayMysqlcheckSummary(OutputInterface $output, array $results, string $operation): void
    {
        $total = \count($results);
        $ok = \count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $errors = \count(array_filter($results, fn($r) => $r['status'] === 'error'));
        $warnings = \count(array_filter($results, fn($r) => $r['status'] === 'warning'));

        $verb = match ($operation) {
            'analyze' => 'analyzed',
            'optimize' => 'optimized',
            default => 'checked',
        };

        $summary = \sprintf('  %d tables %s', $total, $verb);
        if ($ok > 0) {
            $summary .= \sprintf(', <info>%d OK</info>', $ok);
        }
        if ($warnings > 0) {
            $summary .= \sprintf(', <comment>%d warnings</comment>', $warnings);
        }
        if ($errors > 0) {
            $summary .= \sprintf(', <error>%d errors</error>', $errors);
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
