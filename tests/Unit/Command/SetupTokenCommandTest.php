<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Command;

use Nowo\SiteBackupBundle\Command\SetupTokenCommand;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupAccessTokenStore;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

/**
 * REQ-SEC-004 CG F1: first-install flow — a shell user issues the one-time token (and optionally opens the wizard).
 */
final class SetupTokenCommandTest extends TestCase
{
    private string $dir;
    private SetupMarkerManager $markers;
    private SetupAccessTokenStore $tokens;
    private SetupOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->dir     = sys_get_temp_dir() . '/nowo-sbb-tokcmd-' . bin2hex(random_bytes(6));
        $this->markers = new SetupMarkerManager($this->dir . '/setup.required', $this->dir . '/setup.done');
        $this->tokens  = new SetupAccessTokenStore($this->dir . '/setup.token');

        $this->orchestrator = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: new SetupStepFactory(new ConsoleProcessRunner($this->dir, PHP_BINARY, 30), $this->markers, new NullAdminUserProvisioner()),
            progressStorage: new FilesystemSetupProgressStorage($this->dir . '/progress.json'),
            markers: $this->markers,
            profiles: ['fresh_install' => ['steps' => [['type' => 'marker', 'write_done' => true]]]],
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function tester(): CommandTester
    {
        $evaluator = new SetupNeedEvaluator([new MarkerFileDetector($this->markers, false, true)], true, true, $this->markers);

        return new CommandTester(new SetupTokenCommand($this->tokens, $this->markers, $evaluator, $this->orchestrator, '/_setup/'));
    }

    public function testIssuesTokenAndWarnsWhenSetupIsNotRequired(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertSame(1, preg_match('/Token: ([0-9a-f]{48})/', $display, $m));
        $token = $m[1];
        self::assertTrue($this->tokens->isValid($token));
        self::assertStringContainsString('/_setup?token=' . $token, $display);
        self::assertStringContainsString('Setup is not required right now', $display);
        self::assertFalse($this->markers->isRequiredMarked());
    }

    public function testOpenMarksSetupRequiredWithProfile(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--open' => 'post_restore']));

        self::assertTrue($this->markers->isRequiredMarked());
        self::assertSame('post_restore', $this->markers->readRequiredProfile());
        self::assertStringContainsString('profile=post_restore', $tester->getDisplay());
        self::assertStringNotContainsString('Setup is not required right now', $tester->getDisplay());
        self::assertTrue($this->tokens->hasToken());
    }

    public function testOpenWithoutProfileAndRevoke(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--open' => null]));
        self::assertTrue($this->markers->isRequiredMarked());
        self::assertNull($this->markers->readRequiredProfile());
        self::assertStringContainsString('Marked setup.required.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--revoke' => true]));
        self::assertFalse($this->tokens->hasToken());
        self::assertStringContainsString('revoked', $tester->getDisplay());
    }

    public function testOpenRestartsAFinishedRunSoTheStepsRunAgain(): void
    {
        // A completed run left in progress storage (e.g. after setup.done was removed) would complete again at once.
        self::assertSame(SetupProgress::PHASE_COMPLETED, $this->orchestrator->advance('fresh_install')->getPhase());
        $this->markers->clearDone();

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--open' => null]));
        self::assertSame(SetupProgress::PHASE_IDLE, $this->orchestrator->getProgress()->getPhase());
    }
}
