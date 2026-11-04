<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Command\ImportArchiveCommand;
use Nowo\SiteBackupBundle\Command\PasswordInput;
use Nowo\SiteBackupBundle\Command\VerifyBackupCommand;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function fopen;
use function fwrite;
use function json_decode;
use function putenv;
use function rewind;

final class CryptoCommandsTest extends TestCase
{
    private string $root;
    private SiteBackupManager $manager;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
        $this->root = EngineFixtures::tempDir('sbb-cryptocmd-');
        (new Filesystem())->mkdir($this->root . '/project/config');
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
        $archiver      = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60);
        $this->manager = new SiteBackupManager($archiver, new RestoreOrchestrator($this->root . '/project', $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage());
        // @phpstan-ignore-next-line (test process only)
        putenv('SBB_TEST_ARCHIVE_PHRASE=canary-cli-phrase-0001');
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore-next-line (test process only)
        putenv('SBB_TEST_ARCHIVE_PHRASE');
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testCreateVerifyAndImportFromTheCli(): void
    {
        $create = new CommandTester(new CreateBackupCommand($this->manager));
        self::assertSame(Command::SUCCESS, $create->execute(['--format' => 'zip', '--password-env' => 'SBB_TEST_ARCHIVE_PHRASE', '--key-id' => 'k1', '--json' => true]));
        $result = json_decode($create->getDisplay(), true);
        self::assertIsArray($result);
        self::assertTrue($result['encrypted']);

        $verify = new CommandTester(new VerifyBackupCommand($this->manager));
        self::assertSame(Command::FAILURE, $verify->execute(['id' => $result['id']]));
        self::assertSame(Command::SUCCESS, $verify->execute(['id' => $result['id'], '--password-env' => 'SBB_TEST_ARCHIVE_PHRASE']));
        self::assertSame(Command::INVALID, $verify->execute(['id' => $result['id'], '--password-env' => 'lower-case']));

        $import = new CommandTester(new ImportArchiveCommand($this->manager));
        self::assertSame(Command::SUCCESS, $import->execute(['file' => $result['file'], '--password-env' => 'SBB_TEST_ARCHIVE_PHRASE']));
        self::assertStringContainsString('Imported as backup', $import->getDisplay());
        self::assertStringNotContainsString('canary-cli-phrase', $import->getDisplay());
        self::assertSame(Command::FAILURE, $import->execute(['file' => $result['file']]));
        self::assertSame(Command::INVALID, $import->execute(['file' => $result['file'], '--password-env' => 'SBB_UNSET_VARIABLE']));

        self::assertSame(Command::INVALID, $create->execute(['--format' => 'zip', '--password-env' => 'SBB_UNSET_VARIABLE']));
    }

    public function testPasswordFromStdin(): void
    {
        $command = new Command('x');
        PasswordInput::configure($command);

        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "canary-stdin-phrase-01\nignored\n");
        rewind($stdin);
        $input = new ArrayInput(['--password-stdin' => true], $command->getDefinition());
        self::assertSame('canary-stdin-phrase-01', PasswordInput::read($input, $stdin)?->reveal());

        $empty = fopen('php://memory', 'r+');
        self::assertIsResource($empty);
        $this->expectException(InvalidArgumentException::class);
        PasswordInput::read(new ArrayInput(['--password-stdin' => true], $command->getDefinition()), $empty);
    }

    public function testNoPasswordOption(): void
    {
        $command = new Command('x');
        PasswordInput::configure($command);

        self::assertNull(PasswordInput::read(new ArrayInput([], $command->getDefinition())));
        self::assertFalse($command->getDefinition()->hasOption('password'), 'no --password=<value> option (argv is visible)');
    }
}
