<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;

use const DATE_ATOM;

#[AsCommand(name: 'nowo:site-backup:setup-status', description: 'Show setup detectors and progress')]
final class SetupStatusCommand extends Command
{
    public function __construct(
        private readonly SetupNeedEvaluator $evaluator,
        private readonly SetupOrchestrator $orchestrator,
        private readonly SetupMarkerManager $markers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $progress = $this->orchestrator->getProgress();

        $io->definitionList(
            ['setup required' => $this->evaluator->isSetupRequired() ? 'yes' : 'no'],
            ['reasons' => implode(', ', $this->evaluator->getReasons()) ?: '—'],
            ['setup.done' => $this->markers->isDone() ? 'yes' : 'no'],
            ['setup.required' => $this->markers->isRequiredMarked() ? 'yes' : 'no'],
            ['phase' => $progress->getPhase()],
            ['profile' => $progress->getProfile()],
            ['percent' => (string) $progress->getPercent()],
            ['step' => $progress->getCurrentStepId() ?? '—'],
            ['started_at' => $progress->getStartedAt()?->format(DATE_ATOM) ?? '—'],
            ['completed_at' => $progress->getCompletedAt()?->format(DATE_ATOM) ?? '—'],
        );

        return $this->evaluator->isSetupRequired() ? 2 : Command::SUCCESS;
    }
}
