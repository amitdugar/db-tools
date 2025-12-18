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
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'import', description: 'Import a SQL file into a database')]
final class ImportCommand extends Command
{
    use SpinnerTrait;

    public function __construct(private readonly RuntimeInterface $runtime, private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'SQL file to import (.sql, .sql.gz, .sql.zst, .sql.gpg, .zip)')
            ->addArgument('database', InputArgument::OPTIONAL, 'Database name (defaults to profile/env)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('encryption-password', 'e', InputOption::VALUE_REQUIRED, 'Password for encrypted files')
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

        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            $output->writeln(sprintf('<error>SQL file not found: %s</error>', $file));
            return Command::FAILURE;
        }

        $dbPassword = $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password);
        $encryptionPassword = $input->getOption('encryption-password');

        // Check if file is encrypted and try to handle it
        $importService = $this->runtime->importService();
        if ($importService->isEncrypted($file) && $encryptionPassword === null) {
            // Try auto-derived password first (from filename + db password)
            $encryptionPassword = $this->tryAutoDecrypt($input, $output, $file, $dbPassword);
        }

        $options = [
            'file' => $file,
            'database' => (string) $database,
            'host' => $this->resolveHost($input, $profile?->host),
            'port' => $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port),
            'user' => $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user),
            'password' => $dbPassword,
            'encryption_password' => $encryptionPassword,
        ];

        try {
            $output->writeln(sprintf('Importing <info>%s</info> into <info>%s</info>...', basename($file), $database));
            $output->writeln('');
            $start = microtime(true);
            $tickCallback = $this->createSpinnerCallback($output, 'Importing', $start);
            $importService->import($options, $tickCallback);
            $this->clearSpinner($output);
            $elapsed = microtime(true) - $start;
            $output->writeln(sprintf('<info>✓</info> Import completed to <info>%s</info> in %s', $database, $this->formatDuration($elapsed)));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->clearSpinner($output);
            $elapsed = microtime(true) - $start;
            $message = $e->getMessage();

            $output->writeln('');
            $output->writeln(sprintf('<error>✗ Import failed after %s</error>', $this->formatDuration($elapsed)));
            $output->writeln('');

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
                $output->writeln("<error>{$message}</error>");
            }

            return Command::FAILURE;
        }
    }

    private function tryAutoDecrypt(InputInterface $input, OutputInterface $output, string $file, ?string $dbPassword): ?string
    {
        $importService = $this->runtime->importService();
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbtools-import';

        // Try auto-derived password from filename
        $basename = basename($file);
        if (preg_match('/-(\d{8})-(\d{6})-([A-Za-z0-9]{32})/', $basename, $matches)) {
            $randomString = $matches[3];
            $autoPassword = ($dbPassword ?? '') . $randomString;

            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $decrypted = $importService->tryDecrypt($file, $autoPassword, $tempDir);
            if ($decrypted !== null) {
                // Clean up test decryption
                @unlink($decrypted);
                return $autoPassword;
            }
        }

        // Auto-decrypt failed, ask user for password
        $output->writeln('<comment>File is encrypted. Auto-decryption failed.</comment>');

        $helper = $this->getHelper('question');
        $question = new Question('Enter encryption password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        $password = $helper->ask($input, $output, $question);
        if ($password === null || $password === '') {
            return null;
        }

        return (string) $password;
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
