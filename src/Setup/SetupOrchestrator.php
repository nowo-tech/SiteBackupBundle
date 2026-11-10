<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Event\SetupCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepFailedEvent;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Step\CacheClearStep;
use Nowo\SiteBackupBundle\Setup\Step\ConditionalAnswerStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\TabStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;

use function array_slice;
use function count;
use function is_string;
use function sprintf;

final class SetupOrchestrator
{
    public const ADVANCE_AUTOMATIC = 'automatic';

    public const ADVANCE_MANUAL = 'manual';

    /**
     * @param array<string, array{steps: list<array<string, mixed>>, advance_mode?: string}> $profiles
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly SetupStepFactory $stepFactory,
        private readonly SetupProgressStorageInterface $progressStorage,
        private readonly SetupMarkerManager $markers,
        private readonly array $profiles,
        private readonly string $defaultProfile = 'fresh_install',
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly string $defaultAdvanceMode = self::ADVANCE_AUTOMATIC,
        private readonly ?WorkerRestartSignal $workerRestartSignal = null,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getProgress(): SetupProgress
    {
        return $this->progressStorage->load();
    }

    /**
     * Steps of the profile. On a re-opened wizard ({@see isReopened()}) the initial-install-only steps are left out.
     *
     * @return list<SetupStepInterface>
     */
    public function getSteps(?string $profile = null): array
    {
        $profile = $this->resolveProfileName($profile);

        return $this->buildSteps($profile);
    }

    /**
     * 1.32+ (audit IN-I1): the wizard was re-opened on a configured instance ({@see SetupWizardReopener}). Admin
     * creation, `database_url` and the SQL import ({@see InitialInstallOnlySteps}) then neither show nor run, for
     * every caller (page, JSON API, CLI). The state is a server-side marker file, never request input.
     */
    public function isReopened(): bool
    {
        return $this->markers->isReopened();
    }

    public function resolveProfileName(?string $requested = null): string
    {
        if (is_string($requested) && $requested !== '' && isset($this->profiles[$requested])) {
            return $requested;
        }

        $fromMarker = $this->markers->readRequiredProfile();
        if (is_string($fromMarker) && isset($this->profiles[$fromMarker])) {
            return $fromMarker;
        }

        $stored = $this->progressStorage->load()->getProfile();
        if (isset($this->profiles[$stored])) {
            return $stored;
        }

        return $this->defaultProfile;
    }

    public function getAdvanceMode(?string $profile = null): string
    {
        $profile = $this->resolveProfileName($profile);
        $mode    = $this->profiles[$profile]['advance_mode'] ?? null;
        if (is_string($mode) && ($mode === self::ADVANCE_AUTOMATIC || $mode === self::ADVANCE_MANUAL)) {
            return $mode;
        }

        return $this->defaultAdvanceMode === self::ADVANCE_MANUAL
            ? self::ADVANCE_MANUAL
            : self::ADVANCE_AUTOMATIC;
    }

    /**
     * Start or resume wizard; advances through auto steps until form input is needed or done.
     *
     * @param bool $forceAutomatic when true (CLI), ignore profile manual advance_mode and chain auto tabs
     */
    public function advance(?string $profile = null, ?SetupStepInput $input = null, bool $forceAutomatic = false): SetupProgress
    {
        $profile = $this->resolveProfileName($profile);
        $steps   = $this->buildSteps($profile);
        if ($steps === []) {
            throw new InvalidArgumentException(sprintf('Setup profile "%s" has no steps.', $profile));
        }

        $progress = $this->progressStorage->load();
        $ctx      = new SetupContext(
            projectDir: $this->projectDir,
            profile: $profile,
            answers: $progress->getAnswers(),
            completedStepIds: $progress->getCompletedStepIds(),
        );

        if ($progress->getPhase() === SetupProgress::PHASE_IDLE || $progress->getPhase() === SetupProgress::PHASE_FAILED) {
            // Keep the site gate on until marker writes setup.done (FR-SETUP-005).
            if (!$this->markers->isDone()) {
                $this->markers->markRequired($profile);
            }
            $this->eventDispatcher?->dispatch(new SetupStartedEvent($profile));
            $this->logger->info('SiteBackup: setup started.', ['bundle' => 'site_backup', 'action' => 'setup', 'profile' => $profile]);
            $now      = $this->clock->now();
            $progress = new SetupProgress(
                phase: SetupProgress::PHASE_RUNNING,
                profile: $profile,
                percent: 0.0,
                message: 'Setup started',
                completedStepIds: $ctx->getCompletedStepIds(),
                answers: $ctx->getAnswers(),
                updatedAt: $now,
                startedAt: $progress->getStartedAt() ?? $now,
            );
            $this->progressStorage->save($progress);
        }

        $total = count($steps);
        $input ??= new SetupStepInput();
        $advanceMode = $forceAutomatic ? self::ADVANCE_AUTOMATIC : $this->getAdvanceMode($profile);

        foreach ($steps as $index => $step) {
            if (!$step->isEnabled($ctx)) {
                continue;
            }
            if ($step->isComplete($ctx)) {
                continue;
            }

            $progress = $progress->with(
                phase: SetupProgress::PHASE_RUNNING,
                profile: $profile,
                currentStepId: $step->getId(),
                percent: $this->percent($index, $total),
                message: $step->getLabel(),
                clearError: true,
                updatedAt: $this->clock->now(),
            );
            $this->progressStorage->save($progress);

            $result = $step->run($ctx, $input);

            if ($result->isWaitingForInput()) {
                $progress = $progress->with(
                    phase: SetupProgress::PHASE_WAITING,
                    message: $result->getMessage() !== '' ? $result->getMessage() : $step->getLabel(),
                    answers: $ctx->getAnswers(),
                    updatedAt: $this->clock->now(),
                );
                $this->progressStorage->save($progress);

                return $progress;
            }

            if (!$result->isSuccess()) {
                // 1.34+: a failing step may quote the DSN or a password (PDO / client error); progress, event and log
                // get the redacted text only.
                $error    = SecretRedactor::forStorage($result->getMessage());
                $log      = $this->appendLog($progress->getLog(), $result->getLog(), 'ERROR: ' . $error);
                $progress = $progress->with(
                    phase: SetupProgress::PHASE_FAILED,
                    message: $error,
                    error: $error,
                    log: $log,
                    answers: $ctx->getAnswers(),
                    updatedAt: $this->clock->now(),
                );
                $this->progressStorage->save($progress);
                $this->eventDispatcher?->dispatch(new SetupStepFailedEvent($profile, $step->getId(), $error));
                $this->logger->error('SiteBackup: setup step failed.', [
                    'bundle'  => 'site_backup',
                    'action'  => 'setup',
                    'profile' => $profile,
                    'step'    => $step->getId(),
                    'error'   => $error,
                ]);

                return $progress;
            }

            $ctx->markCompleted($step->getId());
            if ($this->stepRequiresWorkerRestart($step, $ctx)) {
                $this->workerRestartSignal?->request('setup:' . $step->getId());
            }
            $log      = $this->appendLog($progress->getLog(), $result->getLog(), $result->getMessage());
            $progress = $progress->with(
                percent: $this->percent($index + 1, $total),
                message: $result->getMessage(),
                completedStepIds: $ctx->getCompletedStepIds(),
                log: $log,
                answers: $ctx->getAnswers(),
                updatedAt: $this->clock->now(),
            );
            $this->progressStorage->save($progress);
            $this->eventDispatcher?->dispatch(new SetupStepCompletedEvent($profile, $step->getId()));
            $this->logger->info('SiteBackup: setup step completed.', ['bundle' => 'site_backup', 'action' => 'setup', 'profile' => $profile, 'step' => $step->getId()]);

            // Manual UI: pause after one successful auto tab so the operator presses Continuar.
            // CLI / forceAutomatic keeps chaining (FR-SETUP-009).
            if ($advanceMode === self::ADVANCE_MANUAL && $step->getUiKind() === 'auto') {
                $progress = $progress->with(
                    phase: SetupProgress::PHASE_RUNNING,
                    currentStepId: $step->getId(),
                    message: $result->getMessage() !== '' ? $result->getMessage() : $step->getLabel(),
                    updatedAt: $this->clock->now(),
                );
                $this->progressStorage->save($progress);

                return $progress;
            }

            // After a form step was satisfied, continue to next auto steps with empty input
            $input = new SetupStepInput();
        }

        $now      = $this->clock->now();
        $progress = $progress->with(
            phase: SetupProgress::PHASE_COMPLETED,
            clearCurrentStepId: true,
            percent: 100.0,
            message: 'Setup completed.',
            clearError: true,
            completedStepIds: $ctx->getCompletedStepIds(),
            answers: $ctx->getAnswers(),
            updatedAt: $now,
            startedAt: $progress->getStartedAt() ?? $now,
            completedAt: $now,
        );
        $this->progressStorage->save($progress);
        $this->eventDispatcher?->dispatch(new SetupCompletedEvent($profile));
        $this->logger->info('SiteBackup: setup completed.', ['bundle' => 'site_backup', 'action' => 'setup', 'profile' => $profile]);

        return $progress;
    }

    public function resetProgress(): void
    {
        $this->logger->warning('SiteBackup: setup progress reset.', ['bundle' => 'site_backup', 'action' => 'setup_reset']);
        $this->progressStorage->save(new SetupProgress());
    }

    /**
     * @return list<SetupStepInterface>
     */
    private function buildSteps(string $profile): array
    {
        $steps = $this->stepFactory->createAll($this->profileSteps($profile));

        return $this->isReopened() ? InitialInstallOnlySteps::withoutInitialInstallOnly($steps) : $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profileSteps(string $profile): array
    {
        if (!isset($this->profiles[$profile])) {
            throw new RuntimeException(sprintf('Unknown setup profile "%s".', $profile));
        }

        return $this->profiles[$profile]['steps'];
    }

    /**
     * `.env.local` and a rebuilt cache are only picked up by workers booted afterwards.
     */
    private function stepRequiresWorkerRestart(SetupStepInterface $step, SetupContext $ctx): bool
    {
        while ($step instanceof TabStep || $step instanceof ConditionalAnswerStep) {
            $step = $step->getInner();
        }

        if ($step instanceof CacheClearStep) {
            return true;
        }

        return $step instanceof DatabaseUrlStep && $ctx->getAnswer('database_url_set') === true;
    }

    private function percent(int $index, int $total): float
    {
        if ($total <= 0) {
            return 100.0;
        }

        return round(($index / $total) * 100, 1);
    }

    /**
     * @param list<string> $existing
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private function appendLog(array $existing, array $extra, string $message): array
    {
        $line = $this->clock->now()->format('H:i:s') . ' ' . $message;
        $log  = $existing;
        foreach ($extra as $e) {
            if ($e !== '') {
                $log[] = $e;
            }
        }
        $log[] = $line;

        return array_slice($log, -200);
    }
}
