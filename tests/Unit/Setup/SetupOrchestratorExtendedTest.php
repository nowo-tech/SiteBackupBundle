<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Event\SetupStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepFailedEvent;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingEventDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

final class SetupOrchestratorExtendedTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-setup-ext-' . uniqid('', true);
        $this->fs->mkdir($this->dir . '/var');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testResolveProfileFromMarkerAndStored(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/_setup-progress.json');
        $factory  = new SetupStepFactory(new ConsoleProcessRunner($this->dir, PHP_BINARY, 30), $markers, new NullAdminUserProvisioner());
        $orch     = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'fresh_install' => ['steps' => [['type' => 'marker']]],
                'post_restore'  => ['steps' => [['type' => 'marker']]],
            ],
            defaultProfile: 'fresh_install',
        );

        self::assertSame('fresh_install', $orch->resolveProfileName('fresh_install'));
        $markers->markRequired('post_restore');
        self::assertSame('post_restore', $orch->resolveProfileName());
    }

    public function testAdvanceDispatchesEventsAndFails(): void
    {
        $failStep = new class implements SetupStepInterface {
            public function getId(): string
            {
                return 'fail';
            }

            public function getLabel(): string
            {
                return 'Fail';
            }

            public function getUiKind(): string
            {
                return 'auto';
            }

            public function isEnabled(SetupContext $ctx): bool
            {
                return true;
            }

            public function isComplete(SetupContext $ctx): bool
            {
                return false;
            }

            public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
            {
                return SetupStepResult::fail('boom', ['detail']);
            }
        };

        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
            customSteps: ['fail' => $failStep],
        );

        /** @var list<class-string> $events */
        $events     = [];
        $dispatcher = new RecordingEventDispatcher($events);

        $orch = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: ['fail_profile' => ['steps' => [['type' => 'fail']]]],
            defaultProfile: 'fail_profile',
            eventDispatcher: $dispatcher,
        );

        $result = $orch->advance('fail_profile');
        self::assertSame(SetupProgress::PHASE_FAILED, $result->getPhase());
        self::assertContains(SetupStartedEvent::class, $events);
        self::assertContains(SetupStepFailedEvent::class, $events);
    }

    public function testEmptyProfileThrows(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req2', $this->dir . '/done2');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/progress2.json');
        $factory  = new SetupStepFactory(new ConsoleProcessRunner($this->dir, PHP_BINARY, 30), $markers, new NullAdminUserProvisioner());
        $orch     = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: ['empty' => ['steps' => []]],
            defaultProfile: 'empty',
        );

        $this->expectException(InvalidArgumentException::class);
        $orch->advance('empty');
    }

    public function testResetProgress(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/req3', $this->dir . '/done3');
        $storage = new FilesystemSetupProgressStorage($this->dir . '/progress3.json');
        $factory = new SetupStepFactory(new ConsoleProcessRunner($this->dir, PHP_BINARY, 30), $markers, new NullAdminUserProvisioner());
        $orch    = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $storage,
            markers: $markers,
            profiles: ['minimal' => ['steps' => [['type' => 'marker']]]],
            defaultProfile: 'minimal',
        );

        $orch->advance('minimal');
        self::assertSame(SetupProgress::PHASE_COMPLETED, $orch->getProgress()->getPhase());
        $orch->resetProgress();
        self::assertSame(SetupProgress::PHASE_IDLE, $orch->getProgress()->getPhase());
    }
}
