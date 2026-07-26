<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

#[AsCommand(name: 'nowo:site-backup:restore', description: 'Restore a backup with integrity checks and progress UI state')]
final class RestoreBackupCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Backup id')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who triggered the restore', 'cli')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        if (!$input->getOption('yes') && !$io->confirm(sprintf('Restore backup %s? This overwrites included paths.', $id), false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        try {
            $progress = $this->manager->restore($id, (string) $input->getOption('actor'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Restore completed (phase=%s, %.1f%%).', $progress->getPhase(), $progress->getPercent()));

        return Command::SUCCESS;
    }
}
