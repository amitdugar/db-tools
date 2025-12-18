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

#[AsCommand(name: 'collation', description: 'Convert database tables and columns to utf8mb4 collation')]
final class CollationCommand extends Command
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
            ->addOption('collation', null, InputOption::VALUE_REQUIRED, 'Target collation (auto-detected if not specified)')
            ->addOption('charset', null, InputOption::VALUE_REQUIRED, 'Target charset', 'utf8mb4')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Convert only this specific table')
            ->addOption('skip-columns', 's', InputOption::VALUE_NONE, 'Skip individual column conversion (only convert tables)')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be converted without making changes')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Process tables in batches (for memory management)', '0')
            ->addOption('enforce-fk-checks', null, InputOption::VALUE_NONE, 'Enforce foreign key checks (by default, FK checks are disabled during conversion)')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)')
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

        $hasErrors = false;

        foreach ($profiles as $name => $profile) {
            $output->writeln(\sprintf('<comment>===== Profile: %s =====</comment>', $name));

            $options = $this->buildOptions($input, $profile);

            try {
                $result = $this->runConversion($input, $output, $options);
                if ($result !== Command::SUCCESS) {
                    $hasErrors = true;
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
            'collation' => $input->getOption('collation'),
            'charset' => $input->getOption('charset') ?? 'utf8mb4',
            'dry_run' => (bool) $input->getOption('dry-run'),
            'skip_columns' => (bool) $input->getOption('skip-columns'),
            'table' => $input->getOption('table'),
            'disable_fk_checks' => !$input->getOption('enforce-fk-checks'),
        ];

        try {
            return $this->runConversion($input, $output, $options);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * @param \DbTools\Config\Profile $profile
     * @return array<string, mixed>
     */
    private function buildOptions(InputInterface $input, \DbTools\Config\Profile $profile): array
    {
        return [
            'database' => (string) $profile->database,
            'host' => $profile->host ?? 'localhost',
            'port' => $profile->port,
            'user' => $profile->user,
            'password' => $profile->password,
            'collation' => $input->getOption('collation'),
            'charset' => $input->getOption('charset') ?? 'utf8mb4',
            'dry_run' => (bool) $input->getOption('dry-run'),
            'skip_columns' => (bool) $input->getOption('skip-columns'),
            'table' => $input->getOption('table'),
            'disable_fk_checks' => !$input->getOption('enforce-fk-checks'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runConversion(InputInterface $input, OutputInterface $output, array $options): int
    {
        $dryRun = (bool) $options['dry_run'];
        $verbose = $output->isVerbose();
        $skipColumns = (bool) $options['skip_columns'];
        $specificTable = $options['table'];
        $batchSize = (int) $input->getOption('batch-size');
        $database = $options['database'];

        $service = $this->runtime->collationService();

        // Auto-detect collation if not specified
        if ($options['collation'] === null) {
            $options['collation'] = $service->getRecommendedCollation($options);
        }

        $output->writeln('');
        $output->writeln(\sprintf('<info>Database:</info> %s', $database));
        $output->writeln(\sprintf('<info>Target:</info>  %s / %s', $options['charset'], $options['collation']));

        if ($dryRun) {
            $output->writeln('<comment>Mode: DRY RUN (no changes will be made)</comment>');
        }
        if ($specificTable) {
            $output->writeln(\sprintf('<comment>Table: %s only</comment>', $specificTable));
        }
        if ($skipColumns) {
            $output->writeln('<comment>Skipping individual column conversion</comment>');
        }
        $output->writeln('');

        // Get tables to show count
        $tables = $service->getTablesNeedingConversion($options);
        $tablesNeedingConversion = array_filter($tables, fn($t) => $t['needs_conversion']);

        $output->writeln(\sprintf('Found <info>%d</info> tables (%d need conversion)', \count($tables), \count($tablesNeedingConversion)));
        $output->writeln('');

        // Progress callback for output
        $options['callback'] = function (string $event, string $table, array $data) use ($output, $verbose): void {
            switch ($event) {
                case 'dependencies_detected':
                    $count = $data['count'] ?? 0;
                    $output->writeln(\sprintf('<info>Detected %d FK relationship(s) - tables will be converted in dependency order</info>', $count));
                    $output->writeln('');
                    break;

                case 'fk_tables_need_conversion':
                    $fkTables = $data['tables'] ?? [];
                    $output->writeln(\sprintf('<fg=yellow>Warning:</> %d table(s) with FK relationships need conversion (FK checks enforced):', \count($fkTables)));
                    foreach ($fkTables as $t) {
                        $output->writeln(\sprintf('  - %s', $t));
                    }
                    $output->writeln('');
                    break;

                case 'fk_checks_disabled':
                    // Silent by default since this is now the normal behavior
                    break;

                case 'table_start':
                    if ($verbose) {
                        $size = isset($data['size_mb']) ? \sprintf(' (%.1f MB)', $data['size_mb']) : '';
                        $output->writeln(\sprintf('Processing table: <info>%s</info>%s', $table, $size));
                    }
                    break;

                case 'table_skipped':
                    if ($verbose) {
                        $output->writeln('  <fg=green>OK</> Table collation already correct');
                    }
                    break;

                case 'columns_all_ok':
                    if ($verbose) {
                        $output->writeln('  <fg=green>OK</> All columns already use correct collation');
                    }
                    break;

                case 'columns_need_conversion':
                    $colCount = $data['count'] ?? 0;
                    $output->writeln(\sprintf('  <fg=cyan>~</> Found %d column(s) needing conversion', $colCount));
                    break;

                case 'table_converted':
                    $duration = isset($data['duration']) ? \sprintf(' in %.2fs', $data['duration']) : '';
                    $output->writeln(\sprintf('  <fg=green>+</> Converted table <info>%s</info>%s', $table, $duration));
                    break;

                case 'table_dry_run':
                    $output->writeln(\sprintf('  <fg=yellow>~</> Would convert table <info>%s</info> from %s', $table, $data['current_collation'] ?? 'unknown'));
                    break;

                case 'table_error':
                    $output->writeln(\sprintf('  <fg=red>x</> Error converting table <info>%s</info>: %s', $table, $data['error'] ?? 'Unknown error'));
                    break;

                case 'column_start':
                    if ($verbose) {
                        $indexes = $data['indexes'] ?? [];
                        if ($indexes !== []) {
                            $output->writeln(\sprintf('    <fg=yellow>!</> Column %s has %d index(es) that may be affected:', $data['column'] ?? '', \count($indexes)));
                            foreach ($indexes as $index) {
                                $indexType = $index['non_unique'] ? 'INDEX' : 'UNIQUE';
                                $output->writeln(\sprintf('      - %s (%s, %s)', $index['index_name'], $indexType, $index['index_type']));
                            }
                        }
                    }
                    break;

                case 'column_converted':
                    if ($verbose) {
                        $duration = isset($data['duration']) ? \sprintf(' in %.2fs', $data['duration']) : '';
                        $output->writeln(\sprintf('    <fg=green>+</> Column %s converted and verified%s', $data['column'] ?? '', $duration));
                    }
                    break;

                case 'column_dry_run':
                    if ($verbose) {
                        $output->writeln(\sprintf('    <fg=yellow>~</> Would convert column %s from %s', $data['column'] ?? '', $data['current_collation'] ?? ''));
                        if (isset($data['sql'])) {
                            $output->writeln(\sprintf('        SQL: %s', $data['sql']));
                        }
                    }
                    break;

                case 'column_verification_failed':
                    $output->writeln(\sprintf('    <fg=red>!</> Column %s conversion succeeded but verification failed', $data['column'] ?? ''));
                    break;

                case 'column_error':
                    $output->writeln(\sprintf('    <fg=red>x</> Error converting column %s: %s', $data['column'] ?? '', $data['error'] ?? 'Unknown error'));
                    if ($verbose && isset($data['sql'])) {
                        $output->writeln(\sprintf('        Failed SQL: %s', $data['sql']));
                    }
                    break;
            }
        };

        $scriptStartTime = microtime(true);

        try {
            // Process in batches if batch size is specified
            if ($batchSize > 0 && $specificTable === null) {
                $result = $this->processInBatches($service, $options, $tables, $batchSize, $output, $verbose);
            } else {
                $result = $service->convert($options);
            }

            $totalDuration = round(microtime(true) - $scriptStartTime, 2);

            // Summary
            $output->writeln('');
            $output->writeln('<info>Summary:</info>');
            $output->writeln(\sprintf('  Tables converted:  <info>%d</info>', $result['tables_converted']));
            $output->writeln(\sprintf('  Tables skipped:    %d', $result['tables_skipped']));
            $output->writeln(\sprintf('  Columns converted: <info>%d</info>', $result['columns_converted']));
            $output->writeln(\sprintf('  Columns skipped:   %d', $result['columns_skipped']));

            if (($result['columns_failed_verification'] ?? 0) > 0) {
                $output->writeln(\sprintf('  Columns failed verification: <fg=red>%d</>', $result['columns_failed_verification']));
            }

            $output->writeln(\sprintf('  Total time:        %.2fs', $totalDuration));

            if ($result['errors'] !== []) {
                $output->writeln('');
                $output->writeln(\sprintf('<error>Errors: %d</error>', \count($result['errors'])));
                foreach ($result['errors'] as $error) {
                    $output->writeln(\sprintf('  <fg=red>-</> %s', $error));
                }
            }

            $output->writeln('');

            if ($dryRun) {
                $output->writeln('<comment>Dry run complete. No changes were made.</comment>');
            } elseif ($result['errors'] === []) {
                $output->writeln('<info>Collation conversion completed successfully!</info>');
            } else {
                $output->writeln('<comment>Conversion completed with some errors.</comment>');
            }

            return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
        } catch (RuntimeException $e) {
            throw $e;
        }
    }

    /**
     * Process tables in batches to prevent memory issues on large databases.
     *
     * @param array<string, mixed> $options
     * @param list<array{table: string, current_collation: string, needs_conversion: bool, size_mb: float}> $tables
     * @return array{tables_converted: int, tables_skipped: int, columns_converted: int, columns_skipped: int, columns_failed_verification: int, errors: list<string>}
     */
    private function processInBatches(
        \DbTools\Service\CollationServiceInterface $service,
        array $options,
        array $tables,
        int $batchSize,
        OutputInterface $output,
        bool $verbose
    ): array {
        $totalTables = \count($tables);
        $batches = (int) ceil($totalTables / $batchSize);

        if ($verbose) {
            $output->writeln(\sprintf('Processing %d tables in %d batches of up to %d tables each', $totalTables, $batches, $batchSize));
            $output->writeln('');
        }

        $aggregatedResult = [
            'tables_converted' => 0,
            'tables_skipped' => 0,
            'columns_converted' => 0,
            'columns_skipped' => 0,
            'columns_failed_verification' => 0,
            'errors' => [],
        ];

        for ($i = 0; $i < $totalTables; $i += $batchSize) {
            $batchTables = \array_slice($tables, $i, $batchSize);
            $batchNumber = (int) floor($i / $batchSize) + 1;

            if ($verbose) {
                $output->writeln(\sprintf('<comment>--- Batch %d of %d ---</comment>', $batchNumber, $batches));
            }

            foreach ($batchTables as $tableInfo) {
                $tableOptions = [...$options, 'table' => $tableInfo['table']];
                $result = $service->convert($tableOptions);

                $aggregatedResult['tables_converted'] += $result['tables_converted'];
                $aggregatedResult['tables_skipped'] += $result['tables_skipped'];
                $aggregatedResult['columns_converted'] += $result['columns_converted'];
                $aggregatedResult['columns_skipped'] += $result['columns_skipped'];
                $aggregatedResult['columns_failed_verification'] += $result['columns_failed_verification'] ?? 0;
                $aggregatedResult['errors'] = [...$aggregatedResult['errors'], ...$result['errors']];
            }

            // Force garbage collection between batches
            if ($batches > 1) {
                if ($verbose) {
                    $output->writeln('<comment>Cleaning up memory between batches...</comment>');
                }
                gc_collect_cycles();
            }
        }

        return $aggregatedResult;
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
