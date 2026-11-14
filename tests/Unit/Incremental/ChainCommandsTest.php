<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\BinlogCheckCommand;
use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Command\ImportArchiveCommand;
use Nowo\SiteBackupBundle\Command\RestoreChainCommand;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Incremental\BackupChain;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisitesInterface;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\Check;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Restore\RestorePathGuard;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function json_decode;
use function touch;

final class ChainCommandsTest extends TestCase
{
    private string $root;

    private string $project;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-chain-cmd-');
        $this->project = $this->root . '/project';
        (new Filesystem())->mkdir([$this->project . '/config', $this->root . '/work']);
        $this->clock = new MockClock('2026-11-01 00:00:00 UTC');
        file_put_contents($this->project . '/config/app.yaml', 'a');
        touch($this->project . '/config/app.yaml', $this->clock->now()->getTimestamp() - 100);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testCreateRestoreAndImportAChainFromTheCli(): void
    {
        $manager = $this->manager($this->root . '/storage');
        $create  = new CommandTester(new CreateBackupCommand($manager));

        self::assertSame(Command::SUCCESS, $create->execute(['--no-database' => true, '--record-file-manifest' => true, '--json' => true]));
        $full = json_decode($create->getDisplay(), true);
        self::assertSame('full', $full['type']);

        $this->clock->sleep(60);
        file_put_contents($this->project . '/config/app.yaml', 'b');
        touch($this->project . '/config/app.yaml', $this->clock->now()->getTimestamp() - 50);
        self::assertSame(Command::SUCCESS, $create->execute(['--incremental-of' => $full['id'], '--json' => true]));
        $inc = json_decode($create->getDisplay(), true);
        self::assertSame('incremental', $inc['type']);
        self::assertSame($full['id'], $inc['parent_id']);

        self::assertSame(Command::INVALID, $create->execute(['--incremental-of' => $inc['id'], '--no-files' => true]));
        self::assertStringContainsString('takes its content from the chain', $create->getDisplay());
        self::assertSame(Command::INVALID, $create->execute(['--incremental-of' => 'missing']));

        $restore = new CommandTester(new RestoreChainCommand($manager));
        self::assertSame(Command::SUCCESS, $restore->execute(['head' => $inc['id'], '--check' => true]));
        self::assertStringContainsString('consistent', $restore->getDisplay());
        self::assertSame(Command::INVALID, $restore->execute(['head' => $inc['id']]));
        self::assertStringContainsString('--force', $restore->getDisplay());
        self::assertSame(Command::INVALID, $restore->execute(['head' => $inc['id'], '--until' => 'not a date', '--force' => true]));
        self::assertSame(Command::INVALID, $restore->execute(['head' => 'missing', '--force' => true]));

        file_put_contents($this->project . '/config/app.yaml', 'live');
        self::assertSame(Command::SUCCESS, $restore->execute(['head' => $inc['id'], '--force' => true, '--until' => '2026-11-01T00:00:30']));
        self::assertStringContainsString('up to 2026-11-01T00:00:30+00:00', $restore->getDisplay());
        self::assertSame('a', (string) file_get_contents($this->project . '/config/app.yaml'));

        // The same chain imported elsewhere in one call.
        $target = $this->manager($this->root . '/elsewhere');
        $import = new CommandTester(new ImportArchiveCommand($target));
        self::assertSame(Command::SUCCESS, $import->execute(['file' => [$this->root . '/storage/' . $full['id'] . '.tar.gz', $this->root . '/storage/' . $inc['id'] . '.tar.gz']]));
        self::assertStringContainsString('nowo:site-backup:restore-chain', $import->getDisplay());
        self::assertCount(2, $target->listBackups());
        self::assertSame(Command::FAILURE, $import->execute(['file' => [$this->root . '/storage/' . $inc['id'] . '.tar.gz', $this->root . '/storage/' . $full['id'] . '.tar.gz']]));

        // A host manager without chain support.
        $plain = new CommandTester(new ImportArchiveCommand($this->createMock(SiteBackupManagerInterface::class)));
        self::assertSame(Command::FAILURE, $plain->execute(['file' => ['a', 'b']]));
        $plainCreate = $this->createMock(SiteBackupManagerInterface::class);
        $plainCreate->method('defaultOptions')->willReturn(new BackupOptions());
        self::assertSame(Command::INVALID, (new CommandTester(new CreateBackupCommand($plainCreate)))->execute(['--incremental-of' => 'x']));
    }

    public function testBrokenChainAndHalfAppliedRestoresAreLoud(): void
    {
        $manager = $this->createMock(IncrementalBackupManagerInterface::class);
        $full    = ChainFixtures::full();
        $chain   = new BackupChain([$full, ChainFixtures::incremental('i1', ChainFixtures::full('other'), 1)]);
        $manager->method('resolveChain')->willReturn($chain);
        $report = new ChainRestoreReport($chain->ids(), [
            new ChainRestoreStep('full', ChainRestoreStep::PART_DATABASE_DUMP, ChainRestoreStep::APPLIED, 'dump imported'),
            new ChainRestoreStep('i1', ChainRestoreStep::PART_BINLOG, ChainRestoreStep::FAILED),
        ], false, null, 'ERROR 1062', ['a note']);
        $manager->method('restoreChain')->willThrowException(new ChainRestoreException($report));

        $tester = new CommandTester(new RestoreChainCommand($manager));
        self::assertSame(Command::FAILURE, $tester->execute(['head' => 'i1', '--check' => true]));
        self::assertStringContainsString('parent of backup i1 is missing', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['head' => 'i1', '--force' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('STOPPED half-way', $display);
        self::assertStringContainsString('dump imported', $display);
        self::assertStringContainsString('a note', $display);

        $other = $this->createMock(IncrementalBackupManagerInterface::class);
        $other->method('resolveChain')->willReturn($chain);
        $other->method('restoreChain')->willThrowException(new RuntimeException('verification failed'));
        $tester = new CommandTester(new RestoreChainCommand($other));
        self::assertSame(Command::FAILURE, $tester->execute(['head' => 'i1', '--force' => true]));
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
    }

    public function testPasswordAndGapExitCodes(): void
    {
        $manager = $this->createMock(IncrementalBackupManagerInterface::class);
        $manager->method('resolveChain')->willReturn(new BackupChain([ChainFixtures::full()]));
        $tester = new CommandTester(new RestoreChainCommand($manager));
        self::assertSame(Command::INVALID, $tester->execute(['head' => 'full', '--force' => true, '--password-env' => 'SBB_NOT_SET_' . bin2hex(random_bytes(3))]));

        $gapping = $this->createMock(SiteBackupManagerInterface::class);
        $gapping->method('defaultOptions')->willReturn(new BackupOptions());
        $gapping->method('createBackup')->willThrowException(new BinlogGapException(BinlogGapException::EXPIRED, 'binlog.000002 is no longer on the server'));
        $create = new CommandTester(new CreateBackupCommand($gapping));
        self::assertSame(CreateBackupCommand::EXIT_BINLOG_GAP, $create->execute([]));
        self::assertStringContainsString('(expired)', $create->getDisplay());
    }

    public function testBinlogCheckCommand(): void
    {
        $ok = $this->createMock(BinlogPrerequisitesInterface::class);
        $ok->method('check')->willReturn([Check::ok('log_bin', 'on'), Check::warning('expiry', 'short')]);
        $tester = new CommandTester(new BinlogCheckCommand($ok));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('WARNING', $tester->getDisplay());

        $bad = $this->createMock(BinlogPrerequisitesInterface::class);
        $bad->method('check')->willReturn([Check::fail('grants', 'Missing on *.*: RELOAD.')]);
        $tester = new CommandTester(new BinlogCheckCommand($bad));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('RELOAD', $tester->getDisplay());
    }

    private function manager(string $storage): SiteBackupManager
    {
        $archiver = new BackupArchiver($this->project, $storage, ['config'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');

        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: new MemoryHistoryStorage(),
            clock: $this->clock,
            chainRestorer: new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project),
        );
    }
}
