<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function is_string;

use const PHP_BINARY;

trait CreatesSiteBackupTestHarness
{
    private string $harnessProjectDir;
    private string $harnessStorageDir;
    private string $harnessHistoryFile;
    private string $harnessProgressFile;
    private Filesystem $harnessFs;

    protected function initHarness(): void
    {
        $this->harnessFs           = new Filesystem();
        $this->harnessProjectDir   = sys_get_temp_dir() . '/nowo-sbb-h-' . uniqid('', true);
        $this->harnessStorageDir   = sys_get_temp_dir() . '/nowo-sbb-h-store-' . uniqid('', true);
        $this->harnessHistoryFile  = sys_get_temp_dir() . '/nowo-sbb-h-history-' . uniqid('', true) . '.jsonl';
        $this->harnessProgressFile = sys_get_temp_dir() . '/nowo-sbb-h-progress-' . uniqid('', true) . '.json';
        $this->harnessFs->mkdir($this->harnessProjectDir . '/config');
        $this->harnessFs->mkdir($this->harnessStorageDir);
        file_put_contents($this->harnessProjectDir . '/config/app.yaml', "foo: bar\n");
    }

    protected function destroyHarness(): void
    {
        $this->harnessFs->remove([
            $this->harnessProjectDir,
            $this->harnessStorageDir,
            dirname($this->harnessHistoryFile),
            dirname($this->harnessProgressFile),
        ]);
    }

    protected function createArchiver(): BackupArchiver
    {
        return new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
    }

    protected function createManager(?EventDispatcherInterface $dispatcher = null): SiteBackupManager
    {
        return new SiteBackupManager(
            $this->createArchiver(),
            new RestoreOrchestrator(
                projectDir: $this->harnessProjectDir,
                archiver: $this->createArchiver(),
                progressStorage: new FilesystemRestoreProgressStorage($this->harnessProgressFile),
                protectedRelativePaths: ['.env.local'],
            ),
            new FilesystemBackupHistoryStorage($this->harnessHistoryFile),
            $dispatcher,
        );
    }

    /**
     * @param array<string, array{steps: list<array<string, mixed>>}> $profiles
     */
    protected function createSetupOrchestrator(array $profiles): SetupOrchestrator
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $progress = new FilesystemSetupProgressStorage($setupDir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );

        $defaultProfile = array_key_first($profiles);

        return new SetupOrchestrator(
            projectDir: $this->harnessProjectDir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: $profiles,
            defaultProfile: is_string($defaultProfile) ? $defaultProfile : 'fresh_install',
        );
    }
}
