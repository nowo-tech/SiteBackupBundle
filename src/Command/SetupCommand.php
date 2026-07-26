<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

#[AsCommand(name: 'nowo:site-backup:setup', description: 'Run the setup wizard pipeline (headless)')]
final class SetupCommand extends Command
{
    public function __construct(private readonly SetupOrchestrator $orchestrator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Setup profile name')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email for admin_user step')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password for admin_user step')
            ->addOption('sample-data', null, InputOption::VALUE_NONE, 'Load sample data when the profile asks')
            ->addOption('skip-sample-data', null, InputOption::VALUE_NONE, 'Skip sample data')
            ->addOption('database-url', null, InputOption::VALUE_REQUIRED, 'DATABASE_URL for database_url step')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Reset setup progress before running');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            $this->orchestrator->resetProgress();
        }

        $data = [];
        if ($input->getOption('admin-email')) {
            $data['email'] = (string) $input->getOption('admin-email');
        }
        if ($input->getOption('admin-password')) {
            $data['password'] = (string) $input->getOption('admin-password');
        }
        if ($input->getOption('database-url')) {
            $data['database_url'] = (string) $input->getOption('database-url');
        }
        if ($input->getOption('sample-data')) {
            $data['action'] = 'load';
        }
        if ($input->getOption('skip-sample-data')) {
            $data['action'] = 'skip';
        }

        $profile = $input->getOption('profile') ? (string) $input->getOption('profile') : null;

        try {
            // Loop: form steps need another advance with filled input
            $guard    = 0;
            $progress = $this->orchestrator->advance($profile, new SetupStepInput($data));
            while ($progress->getPhase() === SetupProgress::PHASE_WAITING && $guard < 20) {
                ++$guard;
                if ($data === []) {
                    $io->error('Interactive step requires --admin-email/--admin-password or --database-url / sample-data flags.');

                    return Command::FAILURE;
                }
                $progress = $this->orchestrator->advance($profile, new SetupStepInput($data));
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($progress->getPhase() === SetupProgress::PHASE_FAILED) {
            $io->error($progress->getError() ?? 'Setup failed.');

            return Command::FAILURE;
        }

        if ($progress->getPhase() === SetupProgress::PHASE_WAITING) {
            $io->warning('Setup waiting for input: ' . ($progress->getMessage() ?? ''));

            return Command::FAILURE;
        }

        $io->success(sprintf('Setup %s (profile=%s).', $progress->getPhase(), $progress->getProfile()));

        return Command::SUCCESS;
    }
}
