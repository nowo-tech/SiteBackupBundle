<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function glob;
use function is_file;
use function is_string;
use function str_repeat;
use function touch;

final class AgeEncryptionTest extends TestCase
{
    private const PHRASE = 'canary-age-phrase-0001';

    private string $root;
    private string $bin;

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-age-');
        $this->bin  = $this->root . '/bin';
        (new Filesystem())->mkdir([$this->bin, $this->root . '/project/config']);
        file_put_contents($this->root . '/project/config/app.yaml', str_repeat("a: b\n", 20));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testRoundTripThroughFdThreeOnly(): void
    {
        $age       = EngineFixtures::fakeAge($this->bin);
        $encryptor = new AgeBinaryEncryptor($age);
        file_put_contents($this->root . '/plain', "payload\nwith lines\n");

        $encryptor->encrypt($this->root . '/plain', $this->root . '/enc.age', ArchivePassword::fromString(self::PHRASE));
        self::assertTrue($encryptor->recognises((string) file_get_contents($this->root . '/enc.age')));
        self::assertStringNotContainsString(self::PHRASE, (string) file_get_contents($this->root . '/enc.age'));

        $encryptor->decrypt($this->root . '/enc.age', $this->root . '/out', ArchivePassword::fromString(self::PHRASE));
        self::assertSame("payload\nwith lines\n", file_get_contents($this->root . '/out'));
        self::assertSame(0o600, fileperms($this->root . '/out') & 0o777);

        try {
            $encryptor->decrypt($this->root . '/enc.age', $this->root . '/out2', ArchivePassword::fromString('another-phrase-00000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }
        self::assertFileDoesNotExist($this->root . '/out2');
        self::assertSame(AgeBinaryEncryptor::NAME, $encryptor->name());
        self::assertSame('.age', $encryptor->suffix());
        self::assertFalse($encryptor->recognises('PK'));
    }

    /**
     * Owner condition (Q-O4 option A): the passphrase is never in argv nor in the environment of age or its plugin.
     */
    public function testPassphraseNeverReachesArgvOrEnvironment(): void
    {
        $age = EngineFixtures::fakeAge($this->bin);
        touch($this->bin . '/slow');
        file_put_contents($this->root . '/plain', 'x');

        $seen = $this->inspectChildrenWhile(fn (callable $tick) => (new AgeBinaryEncryptor($age))->encrypt($this->root . '/plain', $this->root . '/enc.age', ArchivePassword::fromString(self::PHRASE), $tick));

        self::assertNotSame([], $seen, 'age was observed while running');
        foreach ($seen as $process) {
            self::assertStringNotContainsString(self::PHRASE, $process['cmdline']);
            self::assertStringNotContainsString(self::PHRASE, $process['environ']);
            self::assertStringContainsString('AGE_PASSPHRASE_FD=3', $process['environ']);
            self::assertStringNotContainsString('AGE_PASSPHRASE=', str_replace('AGE_PASSPHRASE_', '', $process['environ']));
        }
    }

    public function testAvailabilityChecks(): void
    {
        $cases = [
            'missing binary'  => fn () => new AgeBinaryEncryptor($this->bin . '/nope'),
            'missing on PATH' => static fn () => new AgeBinaryEncryptor('nowo-no-such-age-binary'),
            'too old'         => fn () => new AgeBinaryEncryptor(EngineFixtures::fakeAge($this->root . '/old', 'v1.2.1')),
            'no plugin'       => fn () => new AgeBinaryEncryptor(EngineFixtures::fakeAge($this->root . '/noplugin', 'v1.3.1', false)),
            'no version'      => fn () => new AgeBinaryEncryptor(EngineFixtures::fakeAge($this->root . '/garbled', 'age development build')),
        ];
        (new Filesystem())->mkdir([$this->root . '/old', $this->root . '/noplugin', $this->root . '/garbled']);

        // The real age / plugin may be installed on PATH (CI): hide PATH so only the stand-ins count.
        $path = getenv('PATH');
        // @phpstan-ignore-next-line (test process only, restored below)
        putenv('PATH=/nonexistent');
        try {
            $this->assertUnavailable($cases);
        } finally {
            // @phpstan-ignore-next-line
            putenv('PATH=' . $path);
        }

        $this->expectException(FormatUnavailableException::class);
        new AgeBinaryEncryptor('age', 9);
    }

    /**
     * @param array<string, callable(): AgeBinaryEncryptor> $cases
     */
    private function assertUnavailable(array $cases): void
    {
        foreach ($cases as $name => $factory) {
            try {
                $factory()->assertAvailable();
                self::fail('Expected FormatUnavailableException: ' . $name);
            } catch (FormatUnavailableException $e) {
                self::assertNotSame('', $e->getMessage(), $name);
            }
        }
    }

    public function testFailuresAndTimeoutsLeaveNoOutput(): void
    {
        $age = EngineFixtures::fakeAge($this->bin);
        file_put_contents($this->root . '/plain', 'x');
        touch($this->bin . '/broken');

        try {
            (new AgeBinaryEncryptor($age))->encrypt($this->root . '/plain', $this->root . '/enc.age', ArchivePassword::fromString(self::PHRASE));
            self::fail('Expected FormatUnavailableException');
        } catch (FormatUnavailableException $e) {
            self::assertStringContainsString('age failed (exit 3)', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/enc.age');

        (new Filesystem())->remove($this->bin . '/broken');
        touch($this->bin . '/slow');
        try {
            (new AgeBinaryEncryptor($age, timeoutSeconds: 0))->encrypt($this->root . '/plain', $this->root . '/enc.age', ArchivePassword::fromString(self::PHRASE));
            self::fail('Expected FormatUnavailableException');
        } catch (FormatUnavailableException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/enc.age');
    }

    public function testEncryptedTarGzAndSqlGzBackups(): void
    {
        $age      = EngineFixtures::fakeAge($this->bin);
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], 'echo "SELECT 1;"', 60, workDir: $this->root . '/work', encryptor: new AgeBinaryEncryptor($age));
        $password = ArchivePassword::fromString(self::PHRASE);

        $tar = $archiver->create((new BackupOptions())->withPassword($password, 'k-age'));
        self::assertStringEndsWith('.tar.gz.age', $tar->getFilename());
        self::assertSame(ArchiveFormat::TarGz, $tar->getFormat());
        self::assertSame(AgeBinaryEncryptor::NAME, $tar->getMeta()['encryption']);
        self::assertTrue($tar->isEncrypted());
        self::assertSame([], $tar->getWarnings(), 'no zip-KDF warning for age');
        self::assertSame(['This archive is encrypted: a password is needed.'], $archiver->verifyIntegrity($tar)['errors']);
        self::assertFalse($archiver->verifyIntegrity($tar, null, ArchivePassword::fromString('another-phrase-00000'))['ok']);
        self::assertTrue($archiver->verifyIntegrity($tar, null, $password)['ok']);
        self::assertSame(ArchiveFormat::TarGz, ArchiveFormat::fromFilename($tar->getFilename()));

        $sql = $archiver->create((new BackupOptions(includeFiles: false, format: ArchiveFormat::SqlGz))->withPassword($password));
        self::assertStringEndsWith('.sql.gz.age', $sql->getFilename());
        self::assertTrue($archiver->verifyIntegrity($sql, null, $password)['ok']);

        // Import on another instance, and refusals without a password / without the encryptor.
        $other    = new BackupArchiver($this->root . '/project', $this->root . '/other', ['config'], [], null, 60, encryptor: new AgeBinaryEncryptor($age));
        $imported = $other->import($tar->getAbsolutePath(), $password);
        self::assertSame(AgeBinaryEncryptor::NAME, $imported->getMeta()['imported_from']['encryption']);
        self::assertTrue($imported->getMeta()['imported_from']['encrypted']);
        self::assertSame('database', $other->import($sql->getAbsolutePath(), $password)->getContent());

        try {
            $other->import($tar->getAbsolutePath());
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }
        try {
            (new BackupArchiver($this->root . '/project', $this->root . '/third', ['config'], [], null, 60))->import($tar->getAbsolutePath(), $password);
            self::fail('Expected FormatUnavailableException');
        } catch (FormatUnavailableException) {
        }
        try {
            (new BackupArchiver($this->root . '/project', $this->root . '/third', ['config'], [], null, 60))->create((new BackupOptions())->withPassword($password));
            self::fail('Expected FormatUnavailableException');
        } catch (FormatUnavailableException $e) {
            self::assertStringContainsString('backup.encryption.age', $e->getMessage());
        }
    }

    /**
     * Interop with the real age ≥ 1.3 CLI (CI installs v1.3.1): bundle → CLI and CLI → bundle.
     */
    public function testInteropWithTheRealAgeCli(): void
    {
        $age = (new ExecutableFinder())->find('age');
        if (!is_string($age)) {
            self::markTestSkipped('The age CLI (>= 1.3 with age-plugin-batchpass) is not installed.');
        }
        $encryptor = new AgeBinaryEncryptor($age, 10, 22);
        try {
            $encryptor->assertAvailable();
        } catch (FormatUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
        $env = ['AGE_PASSPHRASE' => self::PHRASE, 'AGE_PASSPHRASE_WORK_FACTOR' => '10'];
        file_put_contents($this->root . '/plain', str_repeat("interop-payload\n", 5000));

        // Bundle encrypts, CLI decrypts.
        $encryptor->encrypt($this->root . '/plain', $this->root . '/bundle.age', ArchivePassword::fromString(self::PHRASE));
        self::assertMatchesRegularExpression('/^age-encryption\.org\/v1\n-> scrypt /', (string) file_get_contents($this->root . '/bundle.age'), 'a standard scrypt stanza');
        (new Process([$age, '-d', '-j', 'batchpass', '-o', $this->root . '/cli-out', $this->root . '/bundle.age'], null, $env))->mustRun();
        self::assertFileEquals($this->root . '/plain', $this->root . '/cli-out');

        // CLI encrypts, bundle decrypts.
        (new Process([$age, '-e', '-j', 'batchpass', '-o', $this->root . '/cli.age', $this->root . '/plain'], null, $env))->mustRun();
        $encryptor->decrypt($this->root . '/cli.age', $this->root . '/bundle-out', ArchivePassword::fromString(self::PHRASE));
        self::assertFileEquals($this->root . '/plain', $this->root . '/bundle-out');

        try {
            $encryptor->decrypt($this->root . '/cli.age', $this->root . '/bad', ArchivePassword::fromString('another-phrase-00000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }

        // The real binary and its plugin never see the passphrase in argv or environ either.
        $seen = $this->inspectChildrenWhile(fn (callable $tick) => (new AgeBinaryEncryptor($age, 18, 22))->decrypt($this->root . '/bundle.age', $this->root . '/again', ArchivePassword::fromString(self::PHRASE), $tick));
        foreach ($seen as $process) {
            self::assertStringNotContainsString(self::PHRASE, $process['cmdline'] . $process['environ']);
        }

        // A CLI-made sql.gz.age imports through the bundle.
        $gz = $this->root . '/dump.sql.gz';
        file_put_contents($gz, (string) gzencode("CREATE TABLE t (id INT);\n"));
        (new Process([$age, '-e', '-j', 'batchpass', '-o', $gz . '.age', $gz], null, $env))->mustRun();
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60, encryptor: $encryptor);
        self::assertSame('database', $archiver->import($gz . '.age', ArchivePassword::fromString(self::PHRASE))->getContent());
    }

    /**
     * Runs $operation with a tick that snapshots /proc/<pid>/cmdline and environ of every descendant process.
     *
     * @param callable(callable(): void): void $operation
     *
     * @return list<array{cmdline: string, environ: string}>
     */
    private function inspectChildrenWhile(callable $operation): array
    {
        if (!is_file('/proc/self/stat')) {
            self::markTestSkipped('/proc is not available.');
        }
        $seen = [];
        $self = (string) @file_get_contents('/proc/self/cmdline');
        $tick = static function () use (&$seen, $self): void {
            $parents = [(string) getmypid() => true];
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
                $fields = explode(' ', (string) @file_get_contents($stat));
                $pid    = $fields[0];
                $ppid   = $fields[3] ?? '';
                if (isset($parents[$ppid])) {
                    $parents[$pid] = true;
                    $cmdline       = @file_get_contents('/proc/' . $pid . '/cmdline');
                    $environ       = @file_get_contents('/proc/' . $pid . '/environ');
                    // EO-I9: a child between fork and exec still shows PHP's own cmdline and environment: skip it.
                    if (is_string($cmdline) && is_string($environ) && $cmdline !== '' && $cmdline !== $self) {
                        $seen[] = ['cmdline' => $cmdline, 'environ' => $environ];
                    }
                }
            }
        };
        $operation($tick);

        return $seen;
    }
}
