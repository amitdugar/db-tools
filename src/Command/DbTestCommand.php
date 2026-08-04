<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use DbTools\Service\BinaryResolver;
use DbTools\Service\ProcessRunner;
use DbTools\Service\ProcessRunnerInterface;
use DbTools\Service\RuntimeInterface;
use DbTools\Service\ServerVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:test', description: 'Test database connectivity')]
final class DbTestCommand extends Command
{
    // db:test talks to the server through its own runner rather than through a
    // service, so unlike the other commands it takes no RuntimeInterface.
    public function __construct(
        private readonly ?ProfilesConfig $config = null,
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly BinaryResolver $binaries = new BinaryResolver(),
    ) {
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
            ->addOption('all', null, InputOption::VALUE_NONE, 'Test connectivity for all configured profiles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all')) {
            return $this->executeAll($output);
        }

        return $this->executeSingle($input, $output);
    }

    private function executeAll(OutputInterface $output): int
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
            $output->writeln(\sprintf('Testing <info>%s</info> [%s]...', $profile->database ?? $name, $name));

            $result = $this->testConnection(
                $profile->host ?? 'localhost',
                $profile->port,
                $profile->user,
                $profile->password,
                $profile->database
            );

            if ($result['success']) {
                $version = $result['flavor'] ?? $result['version'] ?? 'Unknown';
                $output->writeln(\sprintf('  <info>+</info> Connected (%s)', $version));
            } else {
                $output->writeln(\sprintf('  <error>-</error> Failed: %s', $result['error'] ?? 'Unknown error'));
                $hasErrors = true;
            }

            $output->writeln('');
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function executeSingle(InputInterface $input, OutputInterface $output): int
    {
        $requestedProfile = $this->requestedProfileName($input);
        $profile = $this->config?->getProfile($this->resolveProfileName($input));
        if ($requestedProfile !== null && $profile === null) {
            $output->writeln(sprintf('<error>Profile not found:</error> %s', $requestedProfile));
            $output->writeln('Run <info>db-tools config:list</info> to see available profiles.');
            return Command::FAILURE;
        }
        $database = $input->getArgument('database') ?: getenv('DBTOOLS_DATABASE') ?: $profile?->database;

        $host = $this->resolveHost($input, $profile?->host);
        $port = $this->resolveInt($input, 'port', 'DBTOOLS_PORT', $profile?->port);
        $user = $this->resolveString($input, 'user', 'DBTOOLS_USER', $profile?->user);
        $password = $this->resolveString($input, 'password', 'DBTOOLS_PASSWORD', $profile?->password);

        $output->writeln('Testing database connection...');
        $output->writeln('');
        $output->writeln(sprintf('  Host:     <info>%s</info>', $host));
        if ($port !== null) {
            $output->writeln(sprintf('  Port:     <info>%d</info>', $port));
        }
        if ($user !== null) {
            $output->writeln(sprintf('  User:     <info>%s</info>', $user));
        }
        if ($database) {
            $output->writeln(sprintf('  Database: <info>%s</info>', $database));
        }
        $output->writeln('');

        // Test connection using mysql command
        $result = $this->testConnection($host, $port, $user, $password, $database);

        if ($result['success']) {
            $output->writeln('<info>+</info> Connection successful!');
            $output->writeln('');

            if (isset($result['flavor'])) {
                $output->writeln(sprintf('  Server:         <comment>%s</comment>', $result['flavor']));
            }
            if (isset($result['version'])) {
                $output->writeln(sprintf('  Server version: <comment>%s</comment>', $result['version']));
            }
            if (isset($result['uptime'])) {
                $output->writeln(sprintf('  Server uptime:  <comment>%s</comment>', $this->formatUptime((int) $result['uptime'])));
            }

            return Command::SUCCESS;
        }

        $output->writeln('<error>Connection failed!</error>');
        if (isset($result['error'])) {
            $output->writeln('');
            $output->writeln(sprintf('<error>%s</error>', $result['error']));
        }

        return Command::FAILURE;
    }

    /**
     * @return array{success: bool, version?: string, flavor?: string, uptime?: int, error?: string}
     */
    private function testConnection(string $host, ?int $port, ?string $user, ?string $password, ?string $database): array
    {
        $cmd = [$this->binaries->resolve('mysql'), '--host=' . $host, '--batch', '--skip-column-names'];

        if ($port !== null) {
            $cmd[] = '--port=' . $port;
        }
        if ($user !== null) {
            $cmd[] = '--user=' . $user;
        }
        if ($database !== null && $database !== '') {
            $cmd[] = $database;
        }

        $cmd[] = '-e';
        // SHOW GLOBAL STATUS is the one spelling of this that works everywhere.
        // MariaDB has a performance_schema.global_status table but ships with
        // performance_schema OFF by default, so the previous query returned
        // NULL there and db:test silently reported every MariaDB server as
        // having "0s" uptime rather than erroring.
        $cmd[] = "SELECT VERSION(); SHOW GLOBAL STATUS LIKE 'Uptime'";

        $env = $password !== null ? ['MYSQL_PWD' => $password] : [];

        try {
            $stdout = $this->runner->runWithInput($cmd, $env, '')->getOutput();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $lines = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $version = ServerVersion::parse($lines[0] ?? '');

        $uptime = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Uptime')) {
                $parts = explode("\t", $line);
                $uptime = isset($parts[1]) ? (int) trim($parts[1]) : null;
                break;
            }
        }

        $result = [
            'success' => true,
            'version' => $version->raw !== '' ? $version->raw : 'Unknown',
            'flavor' => $version->describe(),
        ];

        if ($uptime !== null) {
            $result['uptime'] = $uptime;
        }

        return $result;
    }

    private function formatUptime(int $seconds): string
    {
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($parts === []) {
            $parts[] = $seconds . 's';
        }

        return implode(' ', $parts);
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

    private function requestedProfileName(InputInterface $input): ?string
    {
        $val = $input->getOption('profile');
        if ($val !== null && $val !== '') {
            return (string) $val;
        }

        $env = getenv('DBTOOLS_PROFILE');
        if ($env !== false && $env !== '') {
            return (string) $env;
        }

        return null;
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
