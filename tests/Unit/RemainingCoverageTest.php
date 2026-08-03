<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Command\ListBackupsCommand;
use Nowo\SiteBackupBundle\Command\SetupCommand;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;

use const PHP_BINARY;

final class RemainingCoverageTest extends TestCase
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

    public function testBackupArchiverRemainingBranches(): void
    {
        $brokenMeta = $this->harnessStorageDir . '/unreadable.meta.json';
        symlink('/definitely/missing/meta-' . uniqid('', true) . '.json', $brokenMeta);
        $listedArchiver = $this->createArchiver();
        $listedArchiver->create('listed', 'phpunit');
        symlink('/definitely/missing/meta-' . uniqid('', true) . '.json', $brokenMeta);
        self::assertCount(1, $listedArchiver->listArtifacts());

        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-branches',
            includePaths: ['missing-dir', 'config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $artifact = $archiver->create('valid-label', 'phpunit');
        self::assertFileExists($artifact->getAbsolutePath());

        $emptyPatternArchiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-patterns',
            includePaths: ['config/app.yaml'],
            excludePatterns: ['', 'config/*.missing'],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        self::assertArrayHasKey('config/app.yaml', $emptyPatternArchiver->create('pattern', 'phpunit')->getChecksums());

        $excludedFileArchiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-excluded-file',
            includePaths: ['config/app.yaml'],
            excludePatterns: ['config/app.yaml'],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        self::assertSame([], $excludedFileArchiver->create('excluded', 'phpunit')->getChecksums());

        $runDatabaseDump = new ReflectionMethod(BackupArchiver::class, 'runDatabaseDump');
        $runDatabaseDump->setAccessible(true);
        $runDatabaseDump->invoke($archiver, sys_get_temp_dir() . '/unused-dump.sql');

        $verify = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));

        $invalidManifestTar = $this->rebuildArchive($artifact->getAbsolutePath(), static function (string $dir): void {
            file_put_contents($dir . '/MANIFEST.json', '{bad');
        });
        $invalidResult = $archiver->verifyIntegrity($this->artifactAtPath($artifact->getId(), $invalidManifestTar));
        self::assertFalse($invalidResult['ok']);
        self::assertStringContainsString('Invalid MANIFEST', $invalidResult['errors'][0]);

        $missingFileTar = $this->rebuildArchive($artifact->getAbsolutePath(), static function (string $dir): void {
            @unlink($dir . '/config/app.yaml');
        });
        $missingResult = $archiver->verifyIntegrity($this->artifactAtPath($artifact->getId(), $missingFileTar));
        self::assertFalse($missingResult['ok']);
        self::assertStringContainsString('Missing file', $missingResult['errors'][0]);

        $mismatchTar = $this->rebuildArchive($artifact->getAbsolutePath(), static function (string $dir): void {
            file_put_contents($dir . '/config/app.yaml', "tampered\n");
        });
        $mismatchResult = $archiver->verifyIntegrity($this->artifactAtPath($artifact->getId(), $mismatchTar));
        self::assertFalse($mismatchResult['ok']);
        self::assertStringContainsString('Checksum mismatch', $mismatchResult['errors'][0]);

        $unreadableDir = $this->harnessProjectDir . '/unreadable';
        $this->harnessFs->mkdir($unreadableDir);
        @chmod($unreadableDir, 0000);
        $unreadableArchiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-unreadable',
            includePaths: ['unreadable'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        if (!is_readable($unreadableDir)) {
            self::assertSame([], $unreadableArchiver->create('unreadable', 'phpunit')->getChecksums());
        }
        @chmod($unreadableDir, 0755);
    }

    public function testBackupArchiverManifestEncodeFailure(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-manifest-fail',
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write MANIFEST.json');
        $archiver->create("\xC3\x28", 'phpunit');
    }

    public function testBackupArchiverSidecarEncodeFailure(): void
    {
        $archiver = $this->createArchiver();
        $artifact = $archiver->create('valid', 'phpunit');
        $bad      = new BackupArtifact(
            id: $artifact->getId(),
            filename: $artifact->getFilename(),
            absolutePath: $artifact->getAbsolutePath(),
            createdAt: $artifact->getCreatedAt(),
            sizeBytes: $artifact->getSizeBytes(),
            archiveSha256: $artifact->getArchiveSha256(),
            label: "\xC3\x28",
        );

        $writeSidecar = new ReflectionMethod(BackupArchiver::class, 'writeSidecar');
        $writeSidecar->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write backup sidecar');
        $writeSidecar->invoke($archiver, $bad);
    }

    public function testBackupArchiverTarCreateFailure(): void
    {
        $archiver   = $this->createArchiver();
        $payloadDir = sys_get_temp_dir() . '/nowo-payload-' . uniqid('', true);
        mkdir($payloadDir);
        file_put_contents($payloadDir . '/file.txt', 'x');
        $targetDir = sys_get_temp_dir() . '/nowo-target-dir-' . uniqid('', true);
        mkdir($targetDir);

        $createTarGz = new ReflectionMethod(BackupArchiver::class, 'createTarGz');
        $createTarGz->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tar create failed');
        $createTarGz->invoke($archiver, $payloadDir, $targetDir);
    }

    public function testBackupArchiverExtractTarFailure(): void
    {
        $badTar = sys_get_temp_dir() . '/nowo-bad-tar-' . uniqid('', true) . '.tar.gz';
        file_put_contents($badTar, 'not-a-tar');
        $artifact = TestFixtures::artifact('bad', $badTar, (string) hash_file('sha256', $badTar));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tar extract failed');
        $this->createArchiver()->extractTo($artifact, sys_get_temp_dir() . '/extract-' . uniqid('', true));
    }

    public function testRestoreProgressStorageRenameFailure(): void
    {
        $progressDir = sys_get_temp_dir() . '/nowo-progress-dir-' . uniqid('', true);
        mkdir($progressDir);
        $blockedPath = $progressDir . '/blocked.json';
        mkdir($blockedPath);

        $restoreStorage = new FilesystemRestoreProgressStorage($blockedPath);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atomically replace');
        $restoreStorage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
    }

    public function testSetupProgressStorageRenameFailure(): void
    {
        $progressDir = sys_get_temp_dir() . '/nowo-setup-dir-' . uniqid('', true);
        mkdir($progressDir);
        $blockedPath = $progressDir . '/blocked.json';
        mkdir($blockedPath);

        $setupStorage = new FilesystemSetupProgressStorage($blockedPath);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atomically replace');
        $setupStorage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
    }

    public function testRestoreProgressStorageEncodeFailure(): void
    {
        $storage = new FilesystemRestoreProgressStorage(sys_get_temp_dir() . '/restore-' . uniqid('', true) . '.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encode restore progress');
        $storage->save(new RestoreProgress(message: "\xC3\x28"));
    }

    public function testSetupProgressStorageEncodeFailure(): void
    {
        $storage = new FilesystemSetupProgressStorage(sys_get_temp_dir() . '/_setup-' . uniqid('', true) . '.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encode setup progress');
        $storage->save(new SetupProgress(message: "\xC3\x28"));
    }

    public function testBackupHistoryStorageFailures(): void
    {
        $historyDir = sys_get_temp_dir() . '/nowo-history-dir-' . uniqid('', true);
        mkdir($historyDir);
        $historyStorage = new FilesystemBackupHistoryStorage($historyDir);
        self::assertSame([], $historyStorage->list(5));

        $badStorage = new FilesystemBackupHistoryStorage(sys_get_temp_dir() . '/history-' . uniqid('', true) . '.jsonl');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encode history entry');
        $badStorage->append(new BackupHistoryEntry('create', new DateTimeImmutable(), actor: "\xC3\x28"));
    }

    public function testBackupHistoryAppendToDirectoryFails(): void
    {
        $historyDir = sys_get_temp_dir() . '/nowo-history-append-' . uniqid('', true);
        mkdir($historyDir);
        $historyStorage = new FilesystemBackupHistoryStorage($historyDir);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append');
        $historyStorage->append(new BackupHistoryEntry('create', new DateTimeImmutable()));
    }

    public function testRestoreRequestSubscriberJsonFormatAndExclusions(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 25.0));
        $manager = $this->createManager();

        $excluded = new RestoreRequestSubscriber(
            true,
            $manager,
            new SiteBackupExclusionMatcher(['/health'], [], [], [], []),
            null,
            'tpl',
        );
        $health = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/health'),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $excluded->onKernelRequest($health);
        self::assertNull($health->getResponse());

        $subscriber  = new RestoreRequestSubscriber(true, $manager, new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $jsonRequest = Request::create('/page');
        $jsonRequest->setRequestFormat('json');
        $jsonEvent = new RequestEvent($this->createMock(HttpKernelInterface::class), $jsonRequest, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($jsonEvent);
        self::assertInstanceOf(JsonResponse::class, $jsonEvent->getResponse());

        $invokeRequest = Request::create('/page');
        $invokeRequest->attributes->set('_controller', InvokableMethodOnlyExcludedController::class);
        $invokeEvent = new RequestEvent($this->createMock(HttpKernelInterface::class), $invokeRequest, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($invokeEvent);
        self::assertNull($invokeEvent->getResponse());
    }

    public function testSetupCommandLoopCatchAndWaiting(): void
    {
        $emptySteps  = $this->createSetupOrchestrator(['empty' => ['steps' => []]]);
        $catchTester = new CommandTester(new SetupCommand($emptySteps));
        self::assertSame(Command::FAILURE, $catchTester->execute(['--profile' => 'empty']));

        $orchestrator = $this->createSetupOrchestrator([
            'db_then_marker' => [
                'steps' => [
                    ['type' => 'database_url', 'optional' => false],
                    ['type' => 'marker'],
                ],
            ],
        ]);
        $loopTester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::FAILURE, $loopTester->execute([
            '--profile'        => 'db_then_marker',
            '--admin-email'    => 'admin@example.com',
            '--admin-password' => 'secret',
        ]));
        self::assertStringContainsString('waiting', strtolower($loopTester->getDisplay()));
    }

    public function testSetupCommandCompletesWithFakeProvisioner(): void
    {
        $orchestrator = $this->createOrchestratorWithProvisioner(new FakeAdminProvisioner(), [
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);
        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--profile'        => 'admin_only',
            '--admin-email'    => 'admin@example.com',
            '--admin-password' => 'secret',
        ]));
    }

    public function testSetupOrchestratorResolveProfileAndDisabledStep(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $storage  = new FilesystemSetupProgressStorage($setupDir . '/progress.json');
        $storage->save(new SetupProgress(profile: 'ghost_profile'));
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker']]],
            'other'         => ['steps' => [['type' => 'marker']]],
        ]);
        self::assertSame('fresh_install', $orchestrator->resolveProfileName());

        $disabled = $this->createSetupOrchestrator([
            'sql_optional' => [
                'steps' => [
                    ['type' => 'sql_file', 'paths' => ['/missing/*.sql'], 'if_exists' => true],
                    ['type' => 'marker'],
                ],
            ],
        ]);
        self::assertSame(SetupProgress::PHASE_COMPLETED, $disabled->advance('sql_optional')->getPhase());
    }

    public function testSiteBackupExtensionReflectionBranches(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([['enabled' => true, 'security' => ['allow_unauthenticated' => true]]], $container);
        $extension = new SiteBackupExtension();

        $configureStorage = new ReflectionMethod(SiteBackupExtension::class, 'configureStorage');
        $configureStorage->setAccessible(true);
        $configureStorage->invoke($extension, $container, [
            'restore' => ['progress_file' => 123],
            'storage' => ['history_file' => null],
        ]);
        self::assertSame(
            '%kernel.project_dir%/var/site-backup/restore-progress.json',
            $container->getDefinition(FilesystemRestoreProgressStorage::class)->getArgument('$filePath'),
        );

        $container->removeDefinition(SiteBackupPanelController::class);
        $configurePanel = new ReflectionMethod(SiteBackupExtension::class, 'configurePanel');
        $configurePanel->setAccessible(true);
        $configurePanel->invoke($extension, $container, ['panel' => ['enabled' => true]]);

        $container->removeDefinition(SetupWizardController::class);
        $config         = (new Processor())->processConfiguration(new Configuration(), [['enabled' => true]]);
        $configureSetup = new ReflectionMethod(SiteBackupExtension::class, 'configureSetup');
        $configureSetup->setAccessible(true);
        $configureSetup->invoke($extension, $container, $config);

        $containerWithWizard = new ContainerBuilder();
        $containerWithWizard->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([['enabled' => true, 'setup' => ['enabled' => false], 'security' => ['allow_unauthenticated' => true]]], $containerWithWizard);
        self::assertFalse($containerWithWizard->hasDefinition(SetupWizardController::class));
    }

    public function testCreateAndListBackupCommands(): void
    {
        $manager = $this->createManager();
        $create  = new CommandTester(new CreateBackupCommand($manager));
        self::assertSame(Command::SUCCESS, $create->execute(['--label' => 'cli-test']));

        $list = new CommandTester(new ListBackupsCommand($manager));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('cli-test', $list->getDisplay());
    }

    public function testCreateBackupCommandFailure(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'false',
            processTimeoutSeconds: 60,
        );
        $manager = new SiteBackupManager(
            $archiver,
            new RestoreOrchestrator(
                projectDir: $this->harnessProjectDir,
                archiver: $archiver,
                progressStorage: new FilesystemRestoreProgressStorage($this->harnessProgressFile),
                protectedRelativePaths: [],
            ),
            new FilesystemBackupHistoryStorage($this->harnessHistoryFile),
        );
        $tester = new CommandTester(new CreateBackupCommand($manager));
        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    private function artifactAtPath(string $id, string $absolutePath): BackupArtifact
    {
        return new BackupArtifact(
            id: $id,
            filename: basename($absolutePath),
            absolutePath: $absolutePath,
            createdAt: new DateTimeImmutable(),
            sizeBytes: (int) filesize($absolutePath),
            archiveSha256: (string) hash_file('sha256', $absolutePath),
        );
    }

    /**
     * @param callable(string): void $modifyPayload
     */
    private function rebuildArchive(string $originalTar, callable $modifyPayload): string
    {
        $extractDir = sys_get_temp_dir() . '/nowo-rebuild-' . uniqid('', true);
        mkdir($extractDir);
        (new Process(['tar', '-xzf', $originalTar, '-C', $extractDir]))->mustRun();
        $modifyPayload($extractDir);
        $newTar = sys_get_temp_dir() . '/nowo-modified-' . uniqid('', true) . '.tar.gz';
        (new Process(['tar', '-czf', $newTar, '-C', $extractDir, '.']))->mustRun();
        $this->harnessFs->remove($extractDir);

        return $newTar;
    }

    /**
     * @param array<string, array{steps: list<array<string, mixed>>}> $profiles
     */
    private function createOrchestratorWithProvisioner(AdminUserProvisionerInterface $provisioner, array $profiles): SetupOrchestrator
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $progress = new FilesystemSetupProgressStorage($setupDir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30),
            $markers,
            $provisioner,
        );

        return new SetupOrchestrator(
            projectDir: $this->harnessProjectDir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: $profiles,
            defaultProfile: array_key_first($profiles) ?? 'fresh_install',
        );
    }
}

final class InvokableMethodOnlyExcludedController
{
    #[ExcludeFromRestore]
    public function __invoke(): string
    {
        return 'ok';
    }
}
