<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Event\SetupCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepFailedEvent;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

use function array_slice;
use function count;
use function is_string;
use function sprintf;

final class SetupOrchestrator
{
    /**
     * @param array<string, array{steps: list<array<string, mixed>>}> $profiles
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly SetupStepFactory $stepFactory,
        private readonly SetupProgressStorageInterface $progressStorage,
        private readonly SetupMarkerManager $markers,
        private readonly array $profiles,
        private readonly string $defaultProfile = 'fresh_install',
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function getProgress(): SetupProgress
    {
        return $this->progressStorage->load();
    }

    /**
     * @return list<SetupStepInterface>
     */
    public function getSteps(?string $profile = null): array
    {
        $profile = $this->resolveProfileName($profile);

        return $this->stepFactory->createAll($this->profileSteps($profile));
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

    /**
     * Start or resume wizard; advances through auto steps until form input is needed or done.
     */
    public function advance(?string $profile = null, ?SetupStepInput $input = null): SetupProgress
    {
        $profile = $this->resolveProfileName($profile);
        $steps   = $this->stepFactory->createAll($this->profileSteps($profile));
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
            $this->eventDispatcher?->dispatch(new SetupStartedEvent($profile));
            $now      = new DateTimeImmutable();
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
                updatedAt: new DateTimeImmutable(),
            );
            $this->progressStorage->save($progress);

            $result = $step->run($ctx, $input);

            if ($result->isWaitingForInput()) {
                $progress = $progress->with(
                    phase: SetupProgress::PHASE_WAITING,
                    message: $result->getMessage() !== '' ? $result->getMessage() : $step->getLabel(),
                    answers: $ctx->getAnswers(),
                    updatedAt: new DateTimeImmutable(),
                );
                $this->progressStorage->save($progress);

                return $progress;
            }

            if (!$result->isSuccess()) {
                $log      = $this->appendLog($progress->getLog(), $result->getLog(), 'ERROR: ' . $result->getMessage());
                $progress = $progress->with(
                    phase: SetupProgress::PHASE_FAILED,
                    message: $result->getMessage(),
                    error: $result->getMessage(),
                    log: $log,
                    answers: $ctx->getAnswers(),
                    updatedAt: new DateTimeImmutable(),
                );
                $this->progressStorage->save($progress);
                $this->eventDispatcher?->dispatch(new SetupStepFailedEvent($profile, $step->getId(), $result->getMessage()));

                return $progress;
            }

            $ctx->markCompleted($step->getId());
            $log      = $this->appendLog($progress->getLog(), $result->getLog(), $result->getMessage());
            $progress = $progress->with(
                percent: $this->percent($index + 1, $total),
                message: $result->getMessage(),
                completedStepIds: $ctx->getCompletedStepIds(),
                log: $log,
                answers: $ctx->getAnswers(),
                updatedAt: new DateTimeImmutable(),
            );
            $this->progressStorage->save($progress);
            $this->eventDispatcher?->dispatch(new SetupStepCompletedEvent($profile, $step->getId()));

            // After a form step was satisfied, continue to next auto steps with empty input
            $input = new SetupStepInput();
        }

        $now      = new DateTimeImmutable();
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

        return $progress;
    }

    public function resetProgress(): void
    {
        $this->progressStorage->save(new SetupProgress());
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
        $line = (new DateTimeImmutable())->format('H:i:s') . ' ' . $message;
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
