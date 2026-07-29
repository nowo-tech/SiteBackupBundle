<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Event;

use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepFailedEvent;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Tests\Unit\TestFixtures;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testBackupEvents(): void
    {
        $artifact = TestFixtures::artifact();
        $created  = new BackupCreatedEvent($artifact, 'cli');
        self::assertSame($artifact, $created->getArtifact());
        self::assertSame('cli', $created->getActor());

        $deleted = new BackupDeletedEvent($artifact);
        self::assertNull($deleted->getActor());
    }

    public function testRestoreEvents(): void
    {
        $artifact = TestFixtures::artifact();
        $progress = new RestoreProgress(phase: RestoreProgress::PHASE_COMPLETED, percent: 100.0);

        $started = new RestoreStartedEvent($artifact, 'panel');
        self::assertSame($artifact, $started->getArtifact());

        $completed = new RestoreCompletedEvent($artifact, $progress, 'panel');
        self::assertSame($progress, $completed->getProgress());

        $failed = new RestoreFailedEvent($artifact, 'boom');
        self::assertSame('boom', $failed->getError());
    }

    public function testSetupEvents(): void
    {
        self::assertSame('fresh_install', (new SetupStartedEvent('fresh_install'))->getProfile());
        self::assertSame('fresh_install', (new SetupCompletedEvent('fresh_install'))->getProfile());
        self::assertSame('step1', (new SetupStepCompletedEvent('fresh_install', 'step1'))->getStepId());
        self::assertSame('err', (new SetupStepFailedEvent('fresh_install', 'step1', 'err'))->getError());
    }
}
