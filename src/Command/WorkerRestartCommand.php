<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'nowo:site-backup:worker-restart', description: 'Show or clear the "restart PHP workers" signal written after restore / setup')]
final class WorkerRestartCommand extends Command
{
    public const EXIT_RESTART_REQUIRED = 3;

    public function __construct(private readonly WorkerRestartSignal $signal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear the signal (run after the workers were restarted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $this->signal->clear();
            $io->success('Worker restart signal cleared.');

            return Command::SUCCESS;
        }

        $state = $this->signal->read();
        if ($state === null) {
            $io->success('No worker restart required.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'PHP workers must be restarted (reason: %s, requested at: %s). Marker: %s',
            $state['reason'],
            $state['requested_at'] ?? '—',
            $this->signal->getMarkerFile(),
        ));

        return self::EXIT_RESTART_REQUIRED;
    }
}
