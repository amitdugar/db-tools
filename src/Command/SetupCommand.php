<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ConfigLoader;
use DbTools\Config\Profile;
use DbTools\Config\ProfilesConfig;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'setup', description: 'Interactively configure db-tools for your project')]
final class SetupCommand extends Command
{
    public function __construct(private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output format: env, config, profile')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Backup output directory')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'Backup retention count')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name (default: "default")')
            ->addOption('no-prompt', null, InputOption::VALUE_NONE, 'Run without prompts (requires --database)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '.';
        $nonInteractive = (bool) $input->getOption('no-prompt');

        // Non-interactive mode
        if ($nonInteractive) {
            return $this->executeNonInteractive($input, $output, $cwd);
        }

        $helper = $this->getHelper('question');

        // Check existing config
        $existingEnv = ConfigLoader::loadFromEnvFile($cwd);
        $hasEnvFile = is_file($cwd . '/.env');
        $hasConfigFile = is_file($cwd . '/db-tools.php') || is_file($cwd . '/db-tools.local.php');

        // Determine output format
        $format = $input->getOption('output');
        if ($format === null) {
            // Auto-detect best option
            if ($hasConfigFile) {
                $output->writeln('<info>Detected existing db-tools.php config.</info>');
                $output->writeln('');
                $format = $this->askChoice($input, $output, $helper, 'What would you like to do?', [
                    'config' => 'Create/update db-tools.php (project config)',
                    'env' => 'Add database variables to .env',
                    'profile' => 'Save to ~/.config/db-tools/profiles.php (personal)',
                ], 'config');
            } elseif ($hasEnvFile && $existingEnv !== null) {
                $output->writeln('<info>Detected .env with database config.</info>');
                $output->writeln("DB_DATABASE={$existingEnv->database}");
                $output->writeln('');
                $output->writeln('db-tools will use this automatically. You can also:');
                $output->writeln('');
                $format = $this->askChoice($input, $output, $helper, 'What would you like to do?', [
                    'done' => 'Nothing - use existing .env (recommended)',
                    'config' => 'Create db-tools.php (for output_dir, retention, etc.)',
                    'env' => 'Update database variables in .env',
                ], 'done');
                if ($format === 'done') {
                    $output->writeln('');
                    $output->writeln('<info>You\'re all set!</info>');
                    $output->writeln('');
                    $output->writeln('Test: <comment>vendor/bin/db-tools config:show --validate</comment>');
                    $output->writeln('Backup: <comment>vendor/bin/db-tools backup --output-dir=/backups</comment>');
                    return Command::SUCCESS;
                }
            } elseif ($hasEnvFile) {
                $output->writeln('<info>Detected .env file (no DB_* variables found).</info>');
                $output->writeln('');
                $format = $this->askChoice($input, $output, $helper, 'How would you like to configure db-tools?', [
                    'env' => 'Add database variables to .env (recommended)',
                    'config' => 'Create db-tools.php (project config file)',
                    'profile' => 'Save to ~/.config/db-tools/profiles.php (personal)',
                ], 'env');
            } else {
                $output->writeln('<info>No existing configuration detected.</info>');
                $output->writeln('');
                $format = $this->askChoice($input, $output, $helper, 'How would you like to configure db-tools?', [
                    'env' => 'Create .env file (recommended for projects)',
                    'config' => 'Create db-tools.php (project config file)',
                    'profile' => 'Save to ~/.config/db-tools/profiles.php (personal)',
                ], 'env');
            }
        }

        $output->writeln('');

        // Collect database credentials
        $answers = $this->collectCredentials($input, $output, $helper, $existingEnv, $format);

        if ($format === 'env') {
            $this->writeEnvFile($cwd, $answers, $output);
        } elseif ($format === 'config') {
            $this->writeConfigFile($cwd, $answers, $output, $helper, $input);
        } else {
            $profileName = $helper->ask($input, $output, new Question('Profile name [default]: ', 'default'));
            $profile = Profile::fromArray($profileName, $answers);
            $this->writeProfile($profile, $output);
        }

        // Create default backup directory
        $this->createBackupDirectory($cwd, $output);

        $output->writeln('');
        $output->writeln('<info>Setup complete!</info>');
        $output->writeln('');
        $output->writeln('Test your configuration:');
        $output->writeln('  <comment>vendor/bin/db-tools config:show --validate</comment>');
        $output->writeln('');
        $output->writeln('Create a backup:');
        $output->writeln('  <comment>vendor/bin/db-tools backup</comment>');
        $output->writeln('');
        $output->writeln('For cron jobs:');
        $output->writeln("  <comment>0 2 * * * cd {$cwd} && vendor/bin/db-tools backup</comment>");

        return Command::SUCCESS;
    }

    private function executeNonInteractive(InputInterface $input, OutputInterface $output, string $cwd): int
    {
        $database = $input->getOption('database');
        if ($database === null) {
            $output->writeln('<error>Non-interactive mode requires --database option</error>');
            return Command::FAILURE;
        }

        $format = $input->getOption('output') ?? 'env';
        if (!\in_array($format, ['env', 'config', 'profile'], true)) {
            $output->writeln('<error>Invalid output format. Use: env, config, or profile</error>');
            return Command::FAILURE;
        }

        $profileName = $input->getOption('profile') ?? 'default';

        $answers = [
            'host' => $input->getOption('host') ?? 'localhost',
            'port' => $input->getOption('port') ?? '3306',
            'database' => $database,
            'user' => $input->getOption('user') ?? 'root',
            'password' => $input->getOption('password') ?? '',
            'output_dir' => $input->getOption('output-dir') ?? './backups',
            'retention' => $input->getOption('retention') ?? '7',
        ];

        if ($format === 'env') {
            $this->writeEnvFileWithProfile($cwd, $profileName, $answers, $output);
        } elseif ($format === 'config') {
            $this->writeConfigFileNonInteractive($cwd, $profileName, $answers, $output);
        } else {
            $profile = Profile::fromArray($profileName, $answers);
            $this->writeProfile($profile, $output);
        }

        $this->createBackupDirectory($cwd, $output);

        $output->writeln('<info>Setup complete!</info>');
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectCredentials(InputInterface $input, OutputInterface $output, mixed $helper, ?Profile $existing, string $format): array
    {
        $defaults = [
            'host' => $existing?->host ?? 'localhost',
            'port' => (string) ($existing?->port ?? 3306),
            'database' => $existing?->database ?? '',
            'user' => $existing?->user ?? '',
            'password' => '',
            'output_dir' => './backups',
            'retention' => '7',
        ];

        $questions = [
            'host' => new Question("Host [{$defaults['host']}]: ", $defaults['host']),
            'port' => new Question("Port [{$defaults['port']}]: ", $defaults['port']),
            'database' => new Question($defaults['database'] ? "Database [{$defaults['database']}]: " : 'Database: ', $defaults['database']),
            'user' => new Question($defaults['user'] ? "User [{$defaults['user']}]: " : 'User: ', $defaults['user']),
            'password' => $this->hiddenQuestion('Password: '),
        ];

        $answers = [];
        foreach ($questions as $key => $question) {
            $answers[$key] = $helper->ask($input, $output, $question);
        }

        // For config files, use sensible defaults (user can press enter to accept)
        if ($format === 'config' || $format === 'profile') {
            $answers['output_dir'] = $defaults['output_dir'];
            $answers['retention'] = $defaults['retention'];
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function writeEnvFile(string $cwd, array $answers, OutputInterface $output): void
    {
        $envPath = $cwd . '/.env';
        $existing = is_file($envPath) ? file_get_contents($envPath) : '';
        if ($existing === false) {
            $existing = '';
        }

        $vars = [
            'DB_HOST' => $answers['host'],
            'DB_PORT' => $answers['port'],
            'DB_DATABASE' => $answers['database'],
            'DB_USERNAME' => $answers['user'],
            'DB_PASSWORD' => $answers['password'],
            'DBTOOLS_OUTPUT_DIR' => './backups',
            'DBTOOLS_RETENTION' => '7',
            'DBTOOLS_COMPRESSION' => 'zstd',
        ];

        $lines = $existing !== '' ? explode("\n", $existing) : [];
        $found = [];

        // Update existing variables
        foreach ($lines as $i => $line) {
            foreach ($vars as $key => $value) {
                if (str_starts_with(trim($line), $key . '=')) {
                    $lines[$i] = $key . '=' . $this->quoteEnvValue($value);
                    $found[$key] = true;
                }
            }
        }

        // Add missing variables
        $additions = [];
        foreach ($vars as $key => $value) {
            if (!isset($found[$key]) && $value !== null && $value !== '') {
                $additions[] = $key . '=' . $this->quoteEnvValue($value);
            }
        }

        if ($additions !== []) {
            $content = implode("\n", $lines);
            if ($content !== '' && !str_ends_with($content, "\n\n") && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            if ($content !== '' && !str_ends_with($content, "\n\n")) {
                $content .= "\n";
            }
            $content .= "# Database configuration (added by db-tools setup)\n";
            $content .= implode("\n", $additions) . "\n";
        } else {
            $content = implode("\n", $lines);
            if (!str_ends_with($content, "\n")) {
                $content .= "\n";
            }
        }

        if (file_put_contents($envPath, $content) === false) {
            throw new RuntimeException("Failed to write .env file: {$envPath}");
        }

        $output->writeln("Updated {$envPath}");
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function writeConfigFile(string $cwd, array $answers, OutputInterface $output, mixed $helper, InputInterface $input): void
    {
        // Ask if credentials should be hardcoded or read from env
        $output->writeln('');
        $credSource = $helper->ask($input, $output, new ChoiceQuestion(
            'How should credentials be stored in db-tools.php?',
            [
                'env' => 'Read from $_ENV (recommended - keeps secrets in .env)',
                'hardcode' => 'Hardcode values (use for db-tools.local.php)',
            ],
            'env'
        ));

        $configPath = $cwd . '/db-tools.php';
        if ($credSource === 'hardcode') {
            // For hardcoded, suggest local file
            $useLocal = $helper->ask($input, $output, new ConfirmationQuestion(
                'Save to db-tools.local.php instead (for .gitignore)? [Y/n] ',
                true
            ));
            if ($useLocal) {
                $configPath = $cwd . '/db-tools.local.php';
            }
        }

        $outputDir = $answers['output_dir'];
        if (str_starts_with($outputDir, './') || !str_starts_with($outputDir, '/')) {
            $outputDir = "__DIR__ . '/" . ltrim($outputDir, './') . "'";
        } else {
            $outputDir = "'{$outputDir}'";
        }

        if ($credSource === 'env') {
            $content = <<<PHP
<?php

// db-tools.php - project configuration
// Credentials are read from .env (DB_HOST, DB_DATABASE, etc.)

return [
    'default' => [
        'host'        => \$_ENV['DB_HOST'] ?? 'localhost',
        'port'        => (int) (\$_ENV['DB_PORT'] ?? 3306),
        'database'    => \$_ENV['DB_DATABASE'] ?? null,
        'user'        => \$_ENV['DB_USERNAME'] ?? null,
        'password'    => \$_ENV['DB_PASSWORD'] ?? null,
        'output_dir'  => {$outputDir},
        'retention'   => {$answers['retention']},
        'compression' => 'zstd',
        'label'       => 'default',
    ],
];

PHP;
        } else {
            $host = $this->exportValue($answers['host']);
            $port = (int) $answers['port'];
            $database = $this->exportValue($answers['database']);
            $user = $this->exportValue($answers['user']);
            $password = $this->exportValue($answers['password']);

            $content = <<<PHP
<?php

// db-tools.local.php - local configuration (add to .gitignore)

return [
    'default' => [
        'host'        => {$host},
        'port'        => {$port},
        'database'    => {$database},
        'user'        => {$user},
        'password'    => {$password},
        'output_dir'  => {$outputDir},
        'retention'   => {$answers['retention']},
        'compression' => 'zstd',
        'label'       => 'default',
    ],
];

PHP;
        }

        if (file_put_contents($configPath, $content) === false) {
            throw new RuntimeException("Failed to write config file: {$configPath}");
        }

        $output->writeln("Created {$configPath}");

        // Remind about .gitignore for local file
        if (str_contains($configPath, '.local.php')) {
            $output->writeln('');
            $output->writeln('<comment>Remember to add db-tools.local.php to .gitignore</comment>');
        }

        // Create backups directory if it doesn't exist
        $backupDir = $cwd . '/' . ltrim($answers['output_dir'], './');
        if (!is_dir($backupDir)) {
            if (@mkdir($backupDir, 0755, true)) {
                $output->writeln("Created {$backupDir}");
            }
        }
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function writeEnvFileWithProfile(string $cwd, string $profileName, array $answers, OutputInterface $output): void
    {
        $envPath = $cwd . '/.env';
        $existing = is_file($envPath) ? file_get_contents($envPath) : '';
        if ($existing === false) {
            $existing = '';
        }

        // Build variable prefix: DB_ for default, DB_PROFILENAME_ for others
        $prefix = $profileName === 'default' ? 'DB_' : 'DB_' . strtoupper($profileName) . '_';

        $vars = [
            $prefix . 'HOST' => $answers['host'],
            $prefix . 'PORT' => $answers['port'],
            $prefix . 'DATABASE' => $answers['database'],
            $prefix . 'USERNAME' => $answers['user'],
            $prefix . 'PASSWORD' => $answers['password'],
        ];

        // Add DBTOOLS_ vars only for default profile
        if ($profileName === 'default') {
            $vars['DBTOOLS_OUTPUT_DIR'] = $answers['output_dir'];
            $vars['DBTOOLS_RETENTION'] = $answers['retention'];
            $vars['DBTOOLS_COMPRESSION'] = 'zstd';
        }

        $lines = $existing !== '' ? explode("\n", $existing) : [];
        $found = [];

        // Update existing variables
        foreach ($lines as $i => $line) {
            foreach ($vars as $key => $value) {
                if (str_starts_with(trim($line), $key . '=')) {
                    $lines[$i] = $key . '=' . $this->quoteEnvValue($value);
                    $found[$key] = true;
                }
            }
        }

        // Add missing variables
        $additions = [];
        foreach ($vars as $key => $value) {
            if (!isset($found[$key]) && $value !== null && $value !== '') {
                $additions[] = $key . '=' . $this->quoteEnvValue($value);
            }
        }

        if ($additions !== []) {
            $content = implode("\n", $lines);
            if ($content !== '' && !str_ends_with($content, "\n\n") && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            if ($content !== '' && !str_ends_with($content, "\n\n")) {
                $content .= "\n";
            }
            $comment = $profileName === 'default'
                ? "# Database configuration (added by db-tools setup)\n"
                : "# Database configuration for '{$profileName}' profile (added by db-tools setup)\n";
            $content .= $comment;
            $content .= implode("\n", $additions) . "\n";
        } else {
            $content = implode("\n", $lines);
            if (!str_ends_with($content, "\n")) {
                $content .= "\n";
            }
        }

        if (file_put_contents($envPath, $content) === false) {
            throw new RuntimeException("Failed to write .env file: {$envPath}");
        }

        $output->writeln("Updated {$envPath}");
        if ($profileName !== 'default') {
            $output->writeln("Use with: <comment>vendor/bin/db-tools backup --profile={$profileName}</comment>");
        }
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function writeConfigFileNonInteractive(string $cwd, string $profileName, array $answers, OutputInterface $output): void
    {
        $configPath = $cwd . '/db-tools.php';

        $outputDir = $answers['output_dir'];
        if (str_starts_with($outputDir, './') || !str_starts_with($outputDir, '/')) {
            $outputDir = "__DIR__ . '/" . ltrim($outputDir, './') . "'";
        } else {
            $outputDir = "'{$outputDir}'";
        }

        $host = $this->exportValue($answers['host']);
        $port = (int) $answers['port'];
        $database = $this->exportValue($answers['database']);
        $user = $this->exportValue($answers['user']);
        $password = $this->exportValue($answers['password']);

        // Load existing config if present
        $existingConfig = [];
        if (is_file($configPath)) {
            $existingConfig = require $configPath;
            if (!\is_array($existingConfig)) {
                $existingConfig = [];
            }
        }

        // Merge new profile into existing config
        $existingConfig[$profileName] = [
            'host' => $answers['host'],
            'port' => (int) $answers['port'],
            'database' => $answers['database'],
            'user' => $answers['user'],
            'password' => $answers['password'],
            'output_dir' => $answers['output_dir'],
            'retention' => (int) $answers['retention'],
            'compression' => 'zstd',
            'label' => $profileName,
        ];

        // Generate PHP config file content
        $profilesCode = '';
        foreach ($existingConfig as $name => $config) {
            $pHost = $this->exportValue($config['host'] ?? null);
            $pPort = (int) ($config['port'] ?? 3306);
            $pDatabase = $this->exportValue($config['database'] ?? null);
            $pUser = $this->exportValue($config['user'] ?? null);
            $pPassword = $this->exportValue($config['password'] ?? null);
            $pOutputDir = $config['output_dir'] ?? './backups';
            if (str_starts_with($pOutputDir, './') || !str_starts_with($pOutputDir, '/')) {
                $pOutputDir = "__DIR__ . '/" . ltrim($pOutputDir, './') . "'";
            } else {
                $pOutputDir = "'{$pOutputDir}'";
            }
            $pRetention = (int) ($config['retention'] ?? 7);
            $pCompression = $this->exportValue($config['compression'] ?? 'zstd');
            $pLabel = $this->exportValue($config['label'] ?? $name);

            $profilesCode .= <<<PHP
    '{$name}' => [
        'host'        => {$pHost},
        'port'        => {$pPort},
        'database'    => {$pDatabase},
        'user'        => {$pUser},
        'password'    => {$pPassword},
        'output_dir'  => {$pOutputDir},
        'retention'   => {$pRetention},
        'compression' => {$pCompression},
        'label'       => {$pLabel},
    ],

PHP;
        }

        $content = <<<PHP
<?php

// db-tools.php - project configuration

return [
{$profilesCode}];

PHP;

        if (file_put_contents($configPath, $content) === false) {
            throw new RuntimeException("Failed to write config file: {$configPath}");
        }

        $action = \count($existingConfig) > 1 ? 'Updated' : 'Created';
        $output->writeln("{$action} {$configPath}");
        if ($profileName !== 'default') {
            $output->writeln("Use with: <comment>vendor/bin/db-tools backup --profile={$profileName}</comment>");
        }
    }

    private function exportValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'null';
        }
        return "'" . addslashes($value) . "'";
    }

    private function quoteEnvValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/[\s"\'#$]/', $value)) {
            return '"' . addslashes($value) . '"';
        }

        return $value;
    }

    private function writeProfile(Profile $profile, OutputInterface $output): void
    {
        $path = $this->userConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new RuntimeException("Unable to create config directory: {$dir}");
        }

        $existing = is_file($path) ? require $path : [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $existing[$profile->name ?? 'default'] = [
            'host' => $profile->host,
            'port' => $profile->port,
            'database' => $profile->database,
            'user' => $profile->user,
            'password' => $profile->password,
            'output_dir' => $profile->outputDir,
            'retention' => $profile->retention,
            'encryption_password' => $profile->encryptionPassword,
            'compression' => $profile->compression,
            'label' => $profile->label,
        ];

        $export = "<?php\n\nreturn " . var_export($existing, true) . ";\n";
        if (file_put_contents($path, $export) === false) {
            throw new RuntimeException("Failed to write config file: {$path}");
        }

        @chmod($path, 0600);
        $output->writeln("Config written to {$path}");
    }

    private function userConfigPath(): string
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.config/db-tools/profiles.php';
    }

    private function hiddenQuestion(string $prompt): Question
    {
        $q = new Question($prompt);
        $q->setHidden(true);
        $q->setHiddenFallback(false);
        return $q;
    }

    /**
     * Ask a choice question with numeric shortcuts (1, 2, 3...).
     *
     * @param array<string, string> $choices Key => description mapping
     * @return string The selected key
     */
    private function createBackupDirectory(string $cwd, OutputInterface $output): void
    {
        $backupDir = $cwd . '/backups';
        if (is_dir($backupDir)) {
            return;
        }

        if (@mkdir($backupDir, 0755, true)) {
            $output->writeln("Created {$backupDir}/");

            // Add .gitignore to exclude backup files
            $gitignore = $backupDir . '/.gitignore';
            if (!is_file($gitignore)) {
                file_put_contents($gitignore, "*\n!.gitignore\n");
            }
        }
    }

    private function askChoice(InputInterface $input, OutputInterface $output, mixed $helper, string $question, array $choices, string $default): string
    {
        $keys = array_keys($choices);
        $defaultNum = (int) array_search($default, $keys, true) + 1;
        $count = \count($keys);

        // Display choices with numbers
        $output->writeln($question);
        $i = 1;
        foreach ($choices as $key => $description) {
            $marker = $key === $default ? ' <info>(default)</info>' : '';
            $output->writeln("  <comment>[{$i}]</comment> {$description}{$marker}");
            $i++;
        }

        $q = new Question("Enter choice [{$defaultNum}]: ", (string) $defaultNum);
        $q->setValidator(function ($answer) use ($keys, $count) {
            $num = (int) $answer;
            if ($num < 1 || $num > $count) {
                throw new \InvalidArgumentException("Please enter a number between 1 and {$count}");
            }
            return $keys[$num - 1];
        });

        return $helper->ask($input, $output, $q);
    }
}
