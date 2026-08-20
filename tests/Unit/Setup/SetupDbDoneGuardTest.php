<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\NullDurableSetupDoneStore;
use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;

final class SetupDbDoneGuardTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testNullStoreNeverClosesWizard(): void
    {
        $guard = $this->createGuard(new NullDurableSetupDoneStore(), new SetupNeedEvaluator([], true));

        self::assertFalse($guard->shouldCloseWizard());
    }

    public function testClosesWizardAndHealsWhenDurableDoneAndDetectorsClear(): void
    {
        $store   = new FakeDurableSetupDoneStore(true);
        $markers = new SetupMarkerManager(
            $this->harnessProjectDir . '/var/site-backup/setup.required',
            $this->harnessProjectDir . '/var/site-backup/setup.done',
        );
        $progressFile = $this->harnessProjectDir . '/var/site-backup/setup-progress.json';
        $storage      = new FilesystemSetupProgressStorage($progressFile);
        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            percent: 50.0,
            updatedAt: new DateTimeImmutable(),
        ));

        $guard = new SetupDbDoneGuard(
            $store,
            new SetupNeedEvaluator([], true),
            $markers,
            $storage,
        );

        self::assertTrue($guard->shouldCloseWizard());
        self::assertTrue($markers->isDone());
        self::assertSame(SetupProgress::PHASE_COMPLETED, $storage->load()->getPhase());
    }

    public function testDoesNotCloseWhenDetectorsStillRequireSetup(): void
    {
        $store   = new FakeDurableSetupDoneStore(true);
        $markers = new SetupMarkerManager(
            $this->harnessProjectDir . '/var/site-backup/setup.required',
            $this->harnessProjectDir . '/var/site-backup/setup.done',
        );
        $markers->markRequired();

        $guard = $this->createGuard(
            $store,
            new SetupNeedEvaluator([
                new class implements SetupNeedDetectorInterface {
                    public function isSetupRequired(): bool
                    {
                        return true;
                    }

                    public function getReason(): string
                    {
                        return 'test';
                    }
                },
            ], true),
            $markers,
        );

        self::assertFalse($guard->shouldCloseWizard());
        self::assertFalse($markers->isDone());
    }

    private function createGuard(
        DurableSetupDoneStoreInterface $store,
        SetupNeedEvaluator $evaluator,
        ?SetupMarkerManager $markers = null,
    ): SetupDbDoneGuard {
        $markers ??= new SetupMarkerManager(
            $this->harnessProjectDir . '/var/site-backup/setup.required',
            $this->harnessProjectDir . '/var/site-backup/setup.done',
        );

        return new SetupDbDoneGuard(
            $store,
            $evaluator,
            $markers,
            new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress.json'),
        );
    }
}
