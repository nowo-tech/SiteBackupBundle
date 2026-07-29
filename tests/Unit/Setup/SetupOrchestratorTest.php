<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SetupOrchestratorTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-setup-orch-' . uniqid('', true);
        $this->fs->mkdir($this->dir . '/var');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testMarkerOnlyProfileCompletes(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/_setup-progress.json');
        $runner   = new ConsoleProcessRunner($this->dir, 'php', 30);
        $factory  = new SetupStepFactory($runner, $markers, new NullAdminUserProvisioner());
        $orch     = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'minimal_marker' => [
                    'steps' => [
                        ['type' => 'marker', 'write_done' => true],
                    ],
                ],
            ],
            defaultProfile: 'minimal_marker',
        );

        $result = $orch->advance('minimal_marker', new SetupStepInput());
        self::assertSame(SetupProgress::PHASE_COMPLETED, $result->getPhase());
        self::assertTrue($markers->isDone());
        self::assertFalse($markers->isRequiredMarked());
    }

    public function testAdminUserNeedsInput(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/_setup-progress.json');
        $runner   = new ConsoleProcessRunner($this->dir, 'php', 30);
        $factory  = new SetupStepFactory($runner, $markers, new NullAdminUserProvisioner());
        $orch     = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'admin_only' => [
                    'steps' => [
                        ['type' => 'admin_user', 'skip_if_admin_exists' => false],
                    ],
                ],
            ],
            defaultProfile: 'admin_only',
        );

        $result = $orch->advance('admin_only');
        self::assertSame(SetupProgress::PHASE_WAITING, $result->getPhase());
    }
}
