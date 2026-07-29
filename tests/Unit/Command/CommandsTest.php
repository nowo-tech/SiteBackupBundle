<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Command;

use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Command\HashPasswordCommand;
use Nowo\SiteBackupBundle\Command\ListBackupsCommand;
use Nowo\SiteBackupBundle\Command\RestoreBackupCommand;
use Nowo\SiteBackupBundle\Command\SetupCommand;
use Nowo\SiteBackupBundle\Command\SetupResetCommand;
use Nowo\SiteBackupBundle\Command\SetupStatusCommand;
use Nowo\SiteBackupBundle\Command\VerifyBackupCommand;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
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

    public function testCreateBackupCommand(): void
    {
        $tester = new CommandTester(new CreateBackupCommand($this->createManager()));
        self::assertSame(Command::SUCCESS, $tester->execute(['--label' => 'x']));
        self::assertStringContainsString('Backup', $tester->getDisplay());
    }

    public function testHashPasswordCommand(): void
    {
        $tester = new CommandTester(new HashPasswordCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['password' => 'secret']));
        self::assertStringContainsString('$', $tester->getDisplay());

        $empty = new CommandTester(new HashPasswordCommand());
        self::assertSame(Command::FAILURE, $empty->execute([]));
    }

    public function testListBackupsCommand(): void
    {
        $manager = $this->createManager();
        $manager->createBackup('listed', 'cli');

        $tester = new CommandTester(new ListBackupsCommand($manager));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('listed', $tester->getDisplay());
    }

    public function testRestoreBackupCommand(): void
    {
        $manager  = $this->createManager();
        $artifact = $manager->createBackup('r', 'cli');

        $abort = new CommandTester(new RestoreBackupCommand($manager));
        $abort->setInputs(['no']);
        self::assertSame(Command::SUCCESS, $abort->execute(['id' => $artifact->getId()]));

        $ok = new CommandTester(new RestoreBackupCommand($manager));
        self::assertSame(Command::SUCCESS, $ok->execute(['id' => $artifact->getId(), '--yes' => true]));
    }

    public function testVerifyBackupCommand(): void
    {
        $manager  = $this->createManager();
        $artifact = $manager->createBackup('v', 'cli');

        $ok = new CommandTester(new VerifyBackupCommand($manager));
        self::assertSame(Command::SUCCESS, $ok->execute(['id' => $artifact->getId()]));

        $fail = new CommandTester(new VerifyBackupCommand($manager));
        self::assertSame(Command::FAILURE, $fail->execute(['id' => 'missing-id']));
    }

    public function testSetupCommand(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'minimal' => ['steps' => [['type' => 'marker', 'write_done' => true]]],
        ]);

        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::SUCCESS, $tester->execute(['--reset' => true, '--profile' => 'minimal']));
    }

    public function testSetupResetCommand(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'minimal' => ['steps' => [['type' => 'marker']]],
        ]);
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');

        $tester = new CommandTester(new SetupResetCommand($orchestrator, $markers));
        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testSetupStatusCommand(): void
    {
        $detector = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return true;
            }

            public function getReason(): string
            {
                return 'yes';
            }
        };
        $evaluator    = new SetupNeedEvaluator([$detector], true);
        $orchestrator = $this->createSetupOrchestrator([
            'minimal' => ['steps' => [['type' => 'marker']]],
        ]);
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');

        $tester = new CommandTester(new SetupStatusCommand($evaluator, $orchestrator, $markers));
        self::assertSame(2, $tester->execute([]));

        $okEvaluator = new SetupNeedEvaluator([], true);
        $okTester    = new CommandTester(new SetupStatusCommand($okEvaluator, $orchestrator, $markers));
        self::assertSame(Command::SUCCESS, $okTester->execute([]));
    }
}
