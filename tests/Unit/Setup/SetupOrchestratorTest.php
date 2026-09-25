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
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

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

    public function testDatabaseUrlAndCacheClearStepsRaiseWorkerRestartSignal(): void
    {
        $this->fs->dumpFile($this->dir . '/bin/console', "<?php echo 'cleared';\n");
        $signal = new WorkerRestartSignal($this->dir . '/var/worker-restart.required');
        $orch   = $this->signalOrchestrator($signal, [
            ['type' => 'database_url', 'optional' => false],
            ['type' => 'cache_clear'],
        ]);

        $orch->advance('p', new SetupStepInput(['database_url' => 'mysql://u:p@db/app']));
        self::assertTrue($signal->isRequested());
        self::assertFileExists($this->dir . '/.env.local');
        self::assertSame('setup:cache_clear_1', $signal->read()['reason'] ?? null);
    }

    public function testConditionalCacheClearStepRaisesWorkerRestartSignal(): void
    {
        $this->fs->dumpFile($this->dir . '/bin/console', "<?php echo 'cleared';\n");
        $signal = new WorkerRestartSignal($this->dir . '/var/worker-restart.required');
        $orch   = $this->signalOrchestrator($signal, [
            ['type' => 'cache_clear', 'id' => 'cc', 'when_answer' => ['mode' => 'fresh']],
        ]);

        $this->fs->dumpFile($this->dir . '/_setup-progress.json', json_encode(
            (new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p', answers: ['mode' => 'fresh']))->toArray(),
            JSON_THROW_ON_ERROR,
        ));

        $orch->advance('p');
        self::assertSame('setup:cc', $signal->read()['reason'] ?? null);
    }

    public function testSkippedDatabaseUrlStepDoesNotRaiseWorkerRestartSignal(): void
    {
        $signal = new WorkerRestartSignal($this->dir . '/var/worker-restart.required');
        $orch   = $this->signalOrchestrator($signal, [
            ['type' => 'database_url', 'optional' => true],
            ['type' => 'marker', 'write_done' => true],
        ]);

        $result = $orch->advance('p', new SetupStepInput());
        self::assertSame(SetupProgress::PHASE_COMPLETED, $result->getPhase());
        self::assertFalse($signal->isRequested());
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function signalOrchestrator(WorkerRestartSignal $signal, array $steps): SetupOrchestrator
    {
        $markers = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $runner  = new ConsoleProcessRunner($this->dir, PHP_BINARY, 30);

        return new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: new SetupStepFactory($runner, $markers, new NullAdminUserProvisioner()),
            progressStorage: new FilesystemSetupProgressStorage($this->dir . '/_setup-progress.json'),
            markers: $markers,
            profiles: ['p' => ['steps' => $steps]],
            defaultProfile: 'p',
            workerRestartSignal: $signal,
        );
    }
}
