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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'restore', description: 'Restore a database from a backup archive')]
final class RestoreCommand extends Command
{
    use BackupListingTrait;
    use SpinnerTrait;

    public function __construct(private readonly RuntimeInterface $runtime, private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::OPTIONAL, 'Path to archive to restore (interactive selection if omitted)')
            ->addArgument('database', InputArgument::OPTIONAL, 'Database name to restore into (defaults to profile/env)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('encryption-password', null, InputOption::VALUE_OPTIONAL, 'Encryption password if archive is protected')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force restore without confirmation prompts')
            ->addOption('skip-safety-backup', null, InputOption::VALUE_NONE, 'Skip pre-restore safety backup')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Profile name to use (or DBTOOLS_PROFILE)')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to search for backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;
        if (!$database) {
            $output->writeln('<error>Database name is required (argument, profile, or DBTOOLS_DATABASE env)</error>');
            return Command::FAILURE;
        }

        // Resolve archive - either from argument or interactive selection
        $archive = $input->getArgument('archive');
        if ($archive === null || $archive === '') {
            $outputDir = $input->getOption('output-dir') ?: (getenv('DBTOOLS_OUTPUT_DIR') ?: null) ?? $profile?->outputDir;
            if ($outputDir === null || $outputDir === '') {
                $output->writeln('<error>No output directory configured. Specify archive path or set output_dir in config.</error>');
                return Command::FAILURE;
            }

            $archive = $this->selectBackupInteractively($input, $output, $outputDir);
            if ($archive === null) {
                $output->writeln('<comment>Restore cancelled.</comment>');
                return Command::SUCCESS;
            }

            // Show selected file feedback
            $output->writeln('');
            $output->writeln(sprintf('<info>Selected:</info> %s', basename($archive)));
            $output->writeln('');
        }

        $options = [
            'archive' => (string) $archive,
            'database' => (string) $database,
            'host' => $this->resolveHost($input, $profile?->host),
            'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
            'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
            'password' => $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password),
            'force' => (bool) $input->getOption('force'),
            'skip_safety_backup' => (bool) $input->getOption('skip-safety-backup'),
            'encryption_password' => $input->getOption('encryption-password') ?? $profile?->encryptionPassword,
            'output_dir' => $input->getOption('output-dir') ?: $profile?->outputDir,
        ];

        try {
            $archiveSize = is_file($archive) ? (int) filesize($archive) : 0;
            $output->writeln(sprintf(
                'Restoring <info>%s</info> <fg=gray>(%s)</> to database <info>%s</info>...',
                basename($archive),
                $this->formatSize($archiveSize),
                $database
            ));
            $output->writeln('');

            $start = microtime(true);
            $tickCallback = $this->createSpinnerCallback($output, 'Restoring', $start);
            $result = $this->runtime->restoreService()->restore($options, $tickCallback);
            $this->clearSpinner($output);
            $elapsed = microtime(true) - $start;

            // Show size info - if archive and SQL are same size, it's uncompressed
            $sizeInfo = $result['archive_size'] === $result['sql_size']
                ? sprintf('%s', $this->formatSize($result['sql_size']))
                : sprintf('%s compressed → %s raw', $this->formatSize($result['archive_size']), $this->formatSize($result['sql_size']));

            $output->writeln(sprintf(
                '<info>✓</info> Restore completed to <info>%s</info> in %s <fg=gray>(%s)</>',
                $database,
                $this->formatDuration($elapsed),
                $sizeInfo
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->clearSpinner($output);
            $elapsed = microtime(true) - $start;
            $message = $e->getMessage();

            $output->writeln('');
            $output->writeln(sprintf('<error>✗ Restore failed after %s</error>', $this->formatDuration($elapsed)));
            $output->writeln('');

            // Provide helpful context based on error type
            if (str_contains($message, 'Lost connection') || str_contains($message, 'server has gone away')) {
                $output->writeln('<error>MySQL connection was lost during import.</error>');
                $output->writeln('');
                $output->writeln('<comment>Possible causes:</comment>');
                $output->writeln('  • MySQL server timeout (max_allowed_packet, wait_timeout)');
                $output->writeln('  • Server ran out of memory');
                $output->writeln('  • Network interruption');
                $output->writeln('');
                $output->writeln('<comment>Suggestions:</comment>');
                $output->writeln('  • Increase max_allowed_packet in MySQL config');
                $output->writeln('  • Increase wait_timeout and net_read_timeout');
                $output->writeln('  • Check MySQL server logs for more details');
            } else {
                $output->writeln('<error>' . $message . '</error>');
            }

            return Command::FAILURE;
        }
    }

    /**
     * Interactively select a backup file from the output directory.
     */
    private function selectBackupInteractively(InputInterface $input, OutputInterface $output, string $outputDir): ?string
    {
        $backups = $this->getSortedBackups($outputDir);

        if ($backups === []) {
            $output->writeln("<error>No backup files found in {$outputDir}</error>");
            return null;
        }

        // Try fzf first if available and terminal is interactive
        if ($input->isInteractive() && $this->canUseFzf()) {
            // fzf available - use it exclusively (no fallback to numbered list)
            return $this->selectWithFzf($backups, $outputDir);
        }

        // Fallback to numbered selection only when fzf is not available
        return $this->selectWithNumbers($input, $output, $backups, $outputDir);
    }

    private ?bool $canUseFzf = null;

    /**
     * Check if fzf can be used (exists, TTY available, and interactive).
     */
    private function canUseFzf(): bool
    {
        if ($this->canUseFzf === null) {
            // Check if fzf command exists
            $result = shell_exec('command -v fzf 2>/dev/null');
            if ($result === null || trim($result) === '') {
                $this->canUseFzf = false;
                return false;
            }

            // Check if we have a TTY (required for fzf interactive mode)
            if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
                $this->canUseFzf = false;
                return false;
            }

            $this->canUseFzf = true;
        }
        return $this->canUseFzf;
    }

    /**
     * Select backup using fzf.
     *
     * @param list<array{name: string, path: string, mtime: int, size: int}> $backups
     */
    private function selectWithFzf(array $backups, string $outputDir): ?string
    {
        // Create temp files for input/output (fzf with TTY needs file-based I/O)
        $inputFile = tempnam(sys_get_temp_dir(), 'dbtools_fzf_in_');
        $outputFile = tempnam(sys_get_temp_dir(), 'dbtools_fzf_out_');

        if ($inputFile === false || $outputFile === false) {
            if ($inputFile !== false) {
                @unlink($inputFile);
            }
            if ($outputFile !== false) {
                @unlink($outputFile);
            }
            return null;
        }

        // Format entries: path\tdisplay_string (tab-separated for fzf --with-nth)
        $lines = [];
        foreach ($backups as $index => $backup) {
            $size = $this->formatSize($backup['size']);
            $date = date('Y-m-d H:i', $backup['mtime']);
            $lines[] = sprintf(
                "%s\t%3d. %s  (%s, %s)",
                $backup['path'],
                $index + 1,
                $backup['name'],
                $size,
                $date
            );
        }

        file_put_contents($inputFile, implode("\n", $lines));

        // Build fzf command that writes selection to output file
        $cmd = sprintf(
            'OUT=%s; export OUT; cat %s | fzf --ansi --height=80%% --reverse --border ' .
            '--prompt=%s --header=%s --delimiter="\t" --with-nth=2.. ' .
            '--bind "enter:execute-silent(echo {1} > \"$OUT\")+abort"',
            escapeshellarg($outputFile),
            escapeshellarg($inputFile),
            escapeshellarg('Select> '),
            escapeshellarg('Select backup to restore (Esc to cancel):')
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(null);

        // Enable TTY for interactive fzf
        if (Process::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (\RuntimeException) {
                // TTY not available, clean up and return null to fall back
                @unlink($inputFile);
                @unlink($outputFile);
                return null;
            }
        }

        $process->run();

        // Read selection from output file
        $selection = @file_get_contents($outputFile);

        // Clean up temp files
        @unlink($inputFile);
        @unlink($outputFile);

        $selection = $selection === false ? '' : trim($selection);
        if ($selection === '') {
            return null;
        }

        return $selection;
    }

    /**
     * Select backup using numbered list.
     *
     * @param list<array{name: string, path: string, mtime: int, size: int}> $backups
     */
    private function selectWithNumbers(InputInterface $input, OutputInterface $output, array $backups, string $outputDir): ?string
    {
        $output->writeln('');
        $output->writeln('<info>Available backups:</info>');
        $output->writeln('');

        $count = min(\count($backups), 20); // Show max 20 entries
        for ($i = 0; $i < $count; $i++) {
            $backup = $backups[$i];
            $size = $this->formatSize($backup['size']);
            $date = date('Y-m-d H:i', $backup['mtime']);
            $output->writeln(sprintf(
                '  <comment>[%2d]</comment> %s  <fg=gray>(%s, %s)</>',
                $i + 1,
                $backup['name'],
                $size,
                $date
            ));
        }

        if (\count($backups) > 20) {
            $output->writeln(sprintf('  <comment>... and %d more</comment>', \count($backups) - 20));
        }

        $output->writeln('');

        $helper = $this->getHelper('question');
        $question = new Question('Enter number to restore (or 0 to cancel): ', '0');
        $question->setValidator(function ($answer) use ($count) {
            $num = (int) $answer;
            if ($num < 0 || $num > $count) {
                throw new \InvalidArgumentException("Please enter a number between 0 and {$count}");
            }
            return $num;
        });

        $choice = $helper->ask($input, $output, $question);

        if ($choice === 0) {
            $output->writeln('<comment>Cancelled.</comment>');
            return null;
        }

        return $backups[$choice - 1]['path'];
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
