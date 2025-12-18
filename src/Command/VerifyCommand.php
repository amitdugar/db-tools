<?php

declare(strict_types=1);

namespace DbTools\Command;

use DbTools\Service\RuntimeInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'verify', description: 'Verify backup archives or directory contents')]
final class VerifyCommand extends Command
{
    public function __construct(private readonly RuntimeInterface $runtime)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'Path to archive file or directory to verify')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Full encryption password if required')
            ->addOption('db-password', null, InputOption::VALUE_OPTIONAL, 'Database password (used to derive encryption password from filename)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'target' => (string) $input->getArgument('target'),
            'password' => $input->getOption('password'),
            'db_password' => $input->getOption('db-password'),
        ];

        try {
            $this->runtime->verifyService()->verify($options);
            $output->writeln('<info>Verification succeeded</info>');
            return Command::SUCCESS;
        } catch (LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
