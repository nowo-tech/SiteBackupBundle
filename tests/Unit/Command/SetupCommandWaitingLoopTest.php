<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Command;

use Nowo\SiteBackupBundle\Command\SetupCommand;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandWaitingLoopTest extends TestCase
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

    public function testWaitingLoopWithoutDataFails(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);

        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::FAILURE, $tester->execute(['--profile' => 'admin_only']));
    }

    public function testWaitingLoopWithDataCompletes(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);

        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--profile'        => 'admin_only',
            '--admin-email'    => 'a@b.c',
            '--admin-password' => 'secret',
        ]));
    }

    public function testSetupFailedProfile(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'bad' => ['steps' => [['type' => 'requirements', 'extensions' => ['definitely_missing_ext_xyz']]]],
        ]);

        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::FAILURE, $tester->execute(['--profile' => 'bad']));
        self::assertSame(SetupProgress::PHASE_FAILED, $orchestrator->getProgress()->getPhase());
    }
}
