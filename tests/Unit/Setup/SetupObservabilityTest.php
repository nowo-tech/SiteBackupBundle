<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/**
 * REQ-OBS-001 / REQ-DI-001: setup runs log start / step failure / completion (redacted) and use the injected clock;
 * the tab-checker registry logs misconfigured ids.
 */
final class SetupObservabilityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-setup-obs-' . uniqid('', true);
        (new Filesystem())->mkdir($this->dir . '/bin');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testSuccessfulRunIsLoggedWithClockTimestamps(): void
    {
        $logger = new RecordingLogger();
        $clock  = new MockClock(new DateTimeImmutable('2026-05-06 07:08:09'));
        $orch   = $this->orchestrator(['steps' => [['type' => 'marker', 'write_done' => true]]], $logger, $clock);

        $result = $orch->advance('p');

        self::assertSame(SetupProgress::PHASE_COMPLETED, $result->getPhase());
        self::assertSame(['info SiteBackup: setup started.', 'info SiteBackup: setup step completed.', 'info SiteBackup: setup completed.'], $logger->lines());
        self::assertSame(['bundle' => 'site_backup', 'action' => 'setup', 'profile' => 'p'], $logger->records[0]['context']);
        self::assertSame('2026-05-06 07:08:09', $result->getCompletedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-06T07:08:09+00:00', trim((string) file_get_contents($this->dir . '/done')));

        $orch->resetProgress();
        $lines = $logger->lines();
        self::assertSame('warning SiteBackup: setup progress reset.', end($lines));
    }

    public function testFailedStepIsLoggedRedacted(): void
    {
        // A console step whose command prints a DSN with a password and fails.
        file_put_contents($this->dir . '/bin/console', "echo 'mysql://app:REDACTED-TEST@db/shop: access denied' >&2\nexit 1\n");
        $logger = new RecordingLogger();
        $orch   = $this->orchestrator(['steps' => [['type' => 'console', 'command' => 'app:import']]], $logger, new MockClock(), 'sh');

        $result = $orch->advance('p');

        self::assertSame(SetupProgress::PHASE_FAILED, $result->getPhase());
        self::assertSame('error SiteBackup: setup step failed.', $logger->lines()[1]);
        $context = $logger->records[1]['context'];
        self::assertSame('p', $context['profile']);
        self::assertIsString($context['error']);
        self::assertStringContainsString('mysql://app:***@db/shop', $context['error']);
        self::assertStringNotContainsString('REDACTED-TEST', (string) json_encode($logger->records));
    }

    public function testTabCheckerRegistryLogsMisconfiguredIds(): void
    {
        $logger  = new RecordingLogger();
        $locator = new SetupTabCheckerLocator(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'boom') {
                    throw new RuntimeException('boom');
                }

                return new stdClass();
            }

            public function has(string $id): bool
            {
                return $id !== 'missing';
            }
        }, $logger);

        self::assertNull($locator->get('missing'));
        self::assertNull($locator->get('boom'));
        self::assertNull($locator->get('not-a-checker'));
        self::assertSame([
            'warning SiteBackup: setup tab checker is not registered.',
            'warning SiteBackup: setup tab checker could not be built.',
            'warning SiteBackup: setup tab checker does not implement SetupTabCheckerInterface.',
        ], $logger->lines());
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['exception']);
        self::assertSame('stdClass', $logger->records[2]['context']['type']);
    }

    /**
     * @param array{steps: list<array<string, mixed>>} $profile
     */
    private function orchestrator(array $profile, RecordingLogger $logger, MockClock $clock, string $php = 'php'): SetupOrchestrator
    {
        $markers = new SetupMarkerManager($this->dir . '/required', $this->dir . '/done', clock: $clock);

        return new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: new SetupStepFactory(new ConsoleProcessRunner($this->dir, $php, 30), $markers, new NullAdminUserProvisioner()),
            progressStorage: new FilesystemSetupProgressStorage($this->dir . '/progress.json'),
            markers: $markers,
            profiles: ['p' => $profile],
            defaultProfile: 'p',
            clock: $clock,
            logger: $logger,
        );
    }
}
