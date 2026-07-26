<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;

#[AsCommand(name: 'nowo:site-backup:setup-reset', description: 'Clear setup.done / progress (dev)')]
final class SetupResetCommand extends Command
{
    public function __construct(
        private readonly SetupOrchestrator $orchestrator,
        private readonly SetupMarkerManager $markers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mark-required', null, InputOption::VALUE_OPTIONAL, 'Also write setup.required with optional profile', false)
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('yes') && !$io->confirm('Reset setup markers and progress?', false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        $this->markers->clearDone();
        $this->orchestrator->resetProgress();

        $markRequired = $input->getOption('mark-required');
        if ($markRequired !== false) {
            $profile = is_string($markRequired) && $markRequired !== '' ? $markRequired : 'fresh_install';
            $this->markers->markRequired($profile);
            $io->note('Marked setup.required (profile=' . $profile . ').');
        }

        $io->success('Setup reset.');

        return Command::SUCCESS;
    }
}
