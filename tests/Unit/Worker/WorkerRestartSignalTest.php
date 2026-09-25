<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Worker;

use Nowo\SiteBackupBundle\Command\WorkerRestartCommand;
use Nowo\SiteBackupBundle\Event\WorkerRestartRequiredEvent;
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

final class WorkerRestartSignalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-worker-signal-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRequestWritesMarkerAndDispatchesEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $reasons    = [];
        $dispatcher->addListener(WorkerRestartRequiredEvent::class, static function (WorkerRestartRequiredEvent $e) use (&$reasons): void {
            $reasons[] = $e->getReason();
        });

        $signal = new WorkerRestartSignal($this->dir . '/var/worker-restart.required', $dispatcher);
        self::assertFalse($signal->isRequested());
        self::assertNull($signal->read());

        $signal->request('restore:abc');

        self::assertTrue($signal->isRequested());
        self::assertSame(['restore:abc'], $reasons);
        $state = $signal->read();
        self::assertNotNull($state);
        self::assertSame('restore:abc', $state['reason']);
        self::assertNotNull($state['requested_at']);

        // A second service instance (other worker / CLI) sees the same marker.
        $other = new WorkerRestartSignal($this->dir . '/var/worker-restart.required');
        self::assertTrue($other->isRequested());

        $other->clear();
        self::assertFalse($signal->isRequested());
        $signal->clear();
        self::assertSame($this->dir . '/var/worker-restart.required', $signal->getMarkerFile());
    }

    public function testReadToleratesCorruptMarker(): void
    {
        $file = $this->dir . '/worker-restart.required';
        (new Filesystem())->dumpFile($file, '{not json');
        $signal = new WorkerRestartSignal($file);

        self::assertSame(['reason' => 'unknown', 'requested_at' => null], $signal->read());

        (new Filesystem())->dumpFile($file, '{"reason": 1}');
        self::assertSame(['reason' => 'unknown', 'requested_at' => null], $signal->read());
    }

    public function testCommandReportsAndClears(): void
    {
        $signal = new WorkerRestartSignal($this->dir . '/worker-restart.required');
        $tester = new CommandTester(new WorkerRestartCommand($signal));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No worker restart required', $tester->getDisplay());

        $signal->request('setup:cache_clear');
        self::assertSame(WorkerRestartCommand::EXIT_RESTART_REQUIRED, $tester->execute([]));
        self::assertStringContainsString('setup:cache_clear', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--clear' => true]));
        self::assertFalse($signal->isRequested());
    }
}
