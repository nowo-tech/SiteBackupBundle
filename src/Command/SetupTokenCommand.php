<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupAccessTokenStore;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function rtrim;

/**
 * Issues the one-time setup access token (1.27+, REQ-SEC-004 CG F1) that opens the web setup wizard on a host without
 * a static setup.setup_token. Only someone with a shell on the server can run it; the token is printed once, stored
 * hashed (0600) and removed when the wizard completes.
 */
#[AsCommand(name: 'nowo:site-backup:setup-token', description: 'Issue a one-time token for the web setup wizard (and optionally open the wizard)')]
final class SetupTokenCommand extends Command
{
    public function __construct(
        private readonly SetupAccessTokenStore $accessTokens,
        private readonly SetupMarkerManager $markers,
        private readonly SetupNeedEvaluator $evaluator,
        private readonly SetupOrchestrator $orchestrator,
        private readonly string $pathPrefix = '/_setup',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('open', null, InputOption::VALUE_OPTIONAL, 'Also write setup.required (optional profile) so the wizard opens on a host that does not detect a need', false)
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Delete the current one-time token instead of issuing one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('revoke')) {
            // @igor-ignore - Console command; not executed in FrankenPHP worker HTTP requests.
            $this->accessTokens->clear();
            $io->success('One-time setup token revoked.');

            return Command::SUCCESS;
        }

        $open = $input->getOption('open');
        if ($open !== false) {
            $profile = is_string($open) && $open !== '' ? $open : null;
            $this->markers->markRequired($profile);
            // A finished run left in progress storage would complete again without running a step: start over.
            if ($this->orchestrator->getProgress()->getPhase() === SetupProgress::PHASE_COMPLETED) {
                $this->orchestrator->resetProgress();
            }
            $io->note('Marked setup.required' . ($profile !== null ? ' (profile=' . $profile . ')' : '') . '.');
        }

        $token = $this->accessTokens->issue();

        $io->success('One-time setup token issued (valid until the wizard completes or --revoke).');
        $io->writeln('Token: ' . $token);
        $io->writeln('Open:  ' . rtrim($this->pathPrefix, '/') . '?token=' . $token);

        if (!$this->evaluator->isSetupRequired()) {
            $io->warning('Setup is not required right now, so the wizard stays closed (302 to /). Re-run with --open to start a run.');
        }

        return Command::SUCCESS;
    }
}
