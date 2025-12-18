<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Config\ProfilesConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:list', description: 'List all available profiles')]
final class ConfigListCommand extends Command
{
    public function __construct(private readonly ?ProfilesConfig $config = null)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profiles = $this->config?->profiles() ?? [];
        $defaultProfile = $this->config?->defaultProfile();

        if ($profiles === []) {
            $output->writeln('<comment>No profiles configured.</comment>');
            $output->writeln('');
            $output->writeln('Quick setup options:');
            $output->writeln('  1. Run <info>db-tools setup</info> for interactive configuration');
            $output->writeln('  2. Set <info>DBTOOLS_DSN</info> env var: mysql://user:pass@host:3306/database');
            $output->writeln('  3. Create <info>db-tools.php</info> in your project directory');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Available profiles:</info>');
        $output->writeln('');

        foreach ($profiles as $name => $profile) {
            $isDefault = $name === $defaultProfile;
            $marker = $isDefault ? ' <comment>(default)</comment>' : '';

            $host = $profile->host ?? 'localhost';
            $port = $profile->port ?? 3306;
            $db = $profile->database ?? '<not set>';

            $output->writeln(sprintf(
                '  <info>%s</info>%s',
                $name,
                $marker
            ));
            $output->writeln(sprintf('    %s:%d/%s', $host, $port, $db));
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
