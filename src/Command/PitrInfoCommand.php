<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Service\RuntimeInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pitr-info', description: 'Inspect PITR metadata and available binlogs')]
final class PitrInfoCommand extends Command
{
    public function __construct(private readonly RuntimeInterface $runtime)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('meta', null, InputOption::VALUE_REQUIRED, 'Path to meta JSON (created during backup)')
            ->addOption('binlog-dir', null, InputOption::VALUE_OPTIONAL, 'Directory containing binlogs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'meta' => $input->getOption('meta'),
            'binlog_dir' => $input->getOption('binlog-dir'),
        ];

        try {
            $info = $this->runtime->pitrInfoService()->info($options);
            $output->writeln(json_encode($info, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        } catch (LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
