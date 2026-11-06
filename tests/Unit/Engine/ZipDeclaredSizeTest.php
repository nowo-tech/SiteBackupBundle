<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use FilesystemIterator;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\UnsafeArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function chmod;
use function chr;
use function count;
use function deflate_add;
use function deflate_init;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function hash;
use function hash_file;
use function hexdec;
use function is_dir;
use function is_file;
use function ksort;
use function mkdir;
use function ord;
use function pack;
use function random_bytes;
use function scandir;
use function sprintf;
use function str_repeat;
use function strlen;
use function strrpos;
use function substr;
use function substr_replace;
use function symlink;
use function touch;
use function unpack;

use const ZLIB_ENCODING_RAW;
use const ZLIB_FINISH;

/**
 * `ZipArchive::statIndex()['size']` is what an archive declares; what comes out of the stream is what the deflate data
 * holds. `backup.extract_limits` must hold on the bytes really written, for plaintext and for AES-256 entries.
 */
final class ZipDeclaredSizeTest extends TestCase
{
    private const PHRASE = 'canary-zip-size-phrase-0000';

    private const LIMIT = 1_000_000;

    private string $root;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is needed.');
        }
        $this->root = EngineFixtures::tempDir('sbb-zipsize-');
        (new Filesystem())->mkdir([$this->root . '/project', $this->root . '/in', $this->root . '/work']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testAnEntryThatLiesAboutItsSizeIsHeldToTheLimitInEveryEntryPoint(): void
    {
        $zip      = $this->root . '/in/liar.zip';
        $archiver = $this->archiver();
        $this->writeZip($zip, [
            ['name' => 'MANIFEST.json', 'data' => '{"checksums":{}}'],
            ['name' => 'bomb.bin', 'data' => str_repeat("\0", 5 * self::LIMIT), 'declared' => 100],
        ]);
        self::assertLessThan(self::LIMIT, filesize($zip));

        // The archive really does declare a small total: the check before extraction cannot see this.
        $stat = new ZipArchive();
        $stat->open($zip);
        self::assertSame(100, $stat->statName('bomb.bin')['size'] ?? null);
        $stat->close();

        $destination = $this->root . '/dest';
        try {
            $archiver->extractTo($this->artifact($zip), $destination);
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException $e) {
            self::assertStringContainsString('backup.extract_limits', $e->getMessage());
        }
        self::assertSame([], $this->entries($destination), 'An aborted extraction leaves nothing behind.');

        $integrity = $archiver->verifyIntegrity($this->artifact($zip));
        self::assertFalse($integrity['ok']);
        self::assertStringContainsString('backup.extract_limits', implode(' ', $integrity['errors']));

        $verified = $archiver->verifyFile($zip);
        self::assertSame(VerifyFileResult::UNSUPPORTED, $verified->status);
        self::assertStringContainsString('backup.extract_limits', implode(' ', $verified->errors));

        try {
            $archiver->import($zip);
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException $e) {
            self::assertStringContainsString('backup.extract_limits', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/storage'), 'A refused import stores nothing.');
        self::assertSame([], $this->entries($this->root . '/work'), 'Every staging directory was removed.');
    }

    public function testTheBytesOfAllEntriesAddUp(): void
    {
        $zip = $this->root . '/in/many.zip';
        $this->writeZip($zip, [
            ['name' => 'a/one.bin', 'data' => str_repeat("\0", (int) (0.6 * self::LIMIT)), 'declared' => 1],
            ['name' => 'b/two.bin', 'data' => str_repeat("\0", (int) (0.6 * self::LIMIT)), 'declared' => 1],
        ]);
        $destination = $this->root . '/dest';
        mkdir($destination);
        file_put_contents($destination . '/already-here', 'host file');

        try {
            $this->archiver()->extractTo($this->artifact($zip), $destination);
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException) {
        }

        // The first entry fitted and was written: the clean-up removes it and its directory, and nothing else.
        self::assertSame(['already-here'], $this->entries($destination));
    }

    public function testEntriesThatShareOneCompressedStreamAreEachCounted(): void
    {
        // Each central directory entry points at the same local entry: the output grows with the entry count alone.
        $zip  = $this->root . '/in/overlap.zip';
        $data = str_repeat("\0", (int) (0.4 * self::LIMIT));
        $this->writeZip($zip, [
            ['name' => 'a.bin', 'data' => $data, 'declared' => 1],
            ['name' => 'b.bin', 'data' => $data, 'declared' => 1, 'overlaps' => 0],
            ['name' => 'c.bin', 'data' => $data, 'declared' => 1, 'overlaps' => 0],
        ]);

        $this->expectException(UnsafeArchiveException::class);
        $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
    }

    public function testTheLimitIsExact(): void
    {
        $exact = $this->root . '/in/exact.zip';
        $this->writeZip($exact, [
            ['name' => 'a.bin', 'data' => str_repeat('x', self::LIMIT - 1), 'declared' => 1],
            ['name' => 'b.bin', 'data' => 'y', 'declared' => 1],
        ]);
        $this->archiver()->extractTo($this->artifact($exact), $this->root . '/ok');
        self::assertSame(self::LIMIT - 1, filesize($this->root . '/ok/a.bin'));
        self::assertSame('y', file_get_contents($this->root . '/ok/b.bin'));

        $over = $this->root . '/in/over.zip';
        $this->writeZip($over, [
            ['name' => 'a.bin', 'data' => str_repeat('x', self::LIMIT), 'declared' => 1],
            ['name' => 'b.bin', 'data' => 'y', 'declared' => 1],
        ]);
        $this->expectException(UnsafeArchiveException::class);
        $this->archiver()->extractTo($this->artifact($over), $this->root . '/nope');
    }

    public function testTheRatioLimitAlsoHoldsOnRealBytes(): void
    {
        // Above the 64 MiB floor only the ratio can refuse: keep the floor out of the way with a tiny max_ratio.
        $zip = $this->root . '/in/ratio.zip';
        $this->writeZip($zip, [['name' => 'r.bin', 'data' => str_repeat("\0", 70 * 1024 * 1024), 'declared' => 1]]);
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', [], [], null, 60, workDir: $this->root . '/work', extractLimits: ['max_ratio' => 10]);

        $this->expectException(UnsafeArchiveException::class);
        $archiver->extractTo($this->artifact($zip), $this->root . '/dest');
    }

    public function testAnAesEntryThatLiesAboutItsSizeIsHeldToTheLimit(): void
    {
        $this->requireAes();
        // The HMAC covers the ciphertext only: the declared sizes are outside what it authenticates.
        $zip = $this->root . '/in/aes.zip';
        $this->aesZip($zip, ['bomb.bin' => str_repeat("\0", 5 * self::LIMIT)]);
        $this->declare($zip, 100);

        $password = ArchivePassword::fromString(self::PHRASE);
        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest', null, $password);
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException $e) {
            self::assertStringContainsString('backup.extract_limits', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
    }

    public function testAuthenticationStillComesBeforeAnyByteIsWritten(): void
    {
        $this->requireAes();
        $zip = $this->root . '/in/aes.zip';
        $this->aesZip($zip, ['first.txt' => 'first', 'second.txt' => str_repeat('s', 4096)]);
        $bytes = (string) file_get_contents($zip);

        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/wrong', null, ArchivePassword::fromString('another-password-000000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }
        self::assertSame([], $this->entries($this->root . '/wrong'));

        // Last ciphertext byte of the last entry: only the HMAC of that entry can notice it. The first entry is intact.
        /** @var array{size: int, offset: int} $end */
        $end      = unpack('Vsize/Voffset', $bytes, (int) strrpos($bytes, "PK\x05\x06") + 12);
        $tampered = $zip . '.bad.zip';
        file_put_contents($tampered, substr_replace($bytes, $bytes[$end['offset'] - 11] ^ "\xFF", $end['offset'] - 11, 1));
        try {
            $this->archiver()->extractTo($this->artifact($tampered), $this->root . '/bad', null, ArchivePassword::fromString(self::PHRASE));
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException $e) {
            self::assertSame(CorruptArchiveException::CHECKSUM, $e->getCode());
        }
        self::assertSame([], $this->entries($this->root . '/bad'), 'first.txt must not be written before the last HMAC is checked.');
    }

    public function testNameAndLinkChecksStillComeBeforeAnyByteIsWritten(): void
    {
        $slip = $this->root . '/in/slip.zip';
        $this->writeZip($slip, [['name' => 'ok.txt', 'data' => 'ok'], ['name' => 'a/../../evil.txt', 'data' => 'evil']]);
        try {
            $this->archiver()->extractTo($this->artifact($slip), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Unsafe archive entry name', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
        self::assertFalse(is_file($this->root . '/evil.txt'));

        $link = $this->root . '/in/link.zip';
        $zip  = new ZipArchive();
        $zip->open($link, ZipArchive::CREATE);
        $zip->addFromString('ok.txt', 'ok');
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0o120777 << 16);
        $zip->close();
        try {
            $this->archiver()->extractTo($this->artifact($link), $this->root . '/dest2');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symbolic links', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest2'));
    }

    public function testMixedAesIsStillRefusedBeforeAnyByteIsWritten(): void
    {
        $this->requireAes();
        $mixed = $this->root . '/in/mixed.zip';
        $zip   = new ZipArchive();
        $zip->open($mixed, ZipArchive::CREATE);
        $zip->addFromString('plain.txt', 'p');
        $zip->addFromString('secret.txt', 's');
        $zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, self::PHRASE);
        $zip->close();

        try {
            $this->archiver()->extractTo($this->artifact($mixed), $this->root . '/dest', null, ArchivePassword::fromString(self::PHRASE));
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException $e) {
            self::assertSame(CorruptArchiveException::NOT_ENCRYPTED, $e->getCode());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
    }

    /**
     * @return iterable<string, array{0: list<array{name: string, data: string}>, 1: list<string>}>
     */
    public static function namesProvider(): iterable
    {
        yield 'a leading dot segment' => [[['name' => './a.txt', 'data' => 'a']], ['a.txt']];
        yield 'an inner dot segment and a double slash' => [[['name' => 'd/./e//f.txt', 'data' => 'f']], ['d', 'd/e', 'd/e/f.txt']];
        yield 'a directory entry' => [[['name' => 'dir/', 'data' => ''], ['name' => 'dir/f.txt', 'data' => 'f']], ['dir', 'dir/f.txt']];
        yield 'a dot directory entry' => [[['name' => './', 'data' => ''], ['name' => 'x.txt', 'data' => 'x']], ['x.txt']];
        yield 'an empty file and a dot name' => [[['name' => 'empty', 'data' => ''], ['name' => '.hidden/.x', 'data' => 'h']], ['.hidden', '.hidden/.x', 'empty']];
        yield 'a trailing dot segment' => [[['name' => 'a/.', 'data' => 'a'], ['name' => 't/./', 'data' => '']], ['a', 't']];
        yield 'unicode, spaces and a dash' => [[['name' => 'ñ/é x.txt', 'data' => 'u'], ['name' => '-dash', 'data' => 'd']], ['-dash', 'ñ', 'ñ/é x.txt']];
    }

    /**
     * @param list<array{name: string, data: string}> $entries
     * @param list<string> $expected
     */
    #[DataProvider('namesProvider')]
    public function testNamesLandWhereTheyAlwaysDid(array $entries, array $expected): void
    {
        $zip = $this->root . '/in/names.zip';
        $this->writeZip($zip, $entries);

        $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
        self::assertSame($expected, $this->paths($this->root . '/dest'));

        // ZipArchive::extractTo() is the reference: the same tree, modes and mtimes.
        $native = new ZipArchive();
        $native->open($zip, ZipArchive::RDONLY);
        self::assertTrue($native->extractTo($this->root . '/native'));
        $native->close();
        self::assertSame($this->tree($this->root . '/native'), $this->tree($this->root . '/dest'));
    }

    public function testALaterDuplicateNameWinsOnEveryPhpVersion(): void
    {
        // ZipArchive::extractTo() wrote the first entry's bytes twice on PHP < 8.4 and the last one's on 8.4+.
        $zip = $this->root . '/in/dup.zip';
        $this->writeZip($zip, [['name' => 'd.txt', 'data' => 'one'], ['name' => 'd.txt', 'data' => 'two']]);

        $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
        self::assertSame('two', file_get_contents($this->root . '/dest/d.txt'));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function collidingNamesProvider(): iterable
    {
        yield 'a file under a file' => [['a', 'a/b']];
        yield 'a file over a directory' => [['a/b', 'a']];
        yield 'a directory entry over a file' => [['a', 'a/']];
        yield 'a file over a directory entry' => [['a/', 'a']];
        yield 'a lone dot' => [['.']];
        yield 'a name no file system takes' => [[str_repeat('l', 300)]];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('collidingNamesProvider')]
    public function testCollidingNamesFailAndCleanUp(array $names): void
    {
        $entries = [];
        foreach ($names as $name) {
            $entries[] = ['name' => $name, 'data' => substr($name, -1) === '/' ? '' : 'x'];
        }
        $zip = $this->root . '/in/collide.zip';
        $this->writeZip($zip, $entries);

        $native = new ZipArchive();
        $native->open($zip, ZipArchive::RDONLY);
        self::assertFalse(@$native->extractTo($this->root . '/native'), 'ZipArchive::extractTo() refuses this too.');
        $native->close();

        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('zip extract failed.', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
    }

    public function testADiskThatCannotTakeTheBytesFailsTheExtraction(): void
    {
        if (!file_exists('/dev/full')) {
            self::markTestSkipped('Needs /dev/full.');
        }
        $zip = $this->root . '/in/full.zip';
        $this->writeZip($zip, [['name' => 'out', 'data' => 'bytes that cannot be written']]);
        mkdir($this->root . '/dest');
        symlink('/dev/full', $this->root . '/dest/out');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zip extract failed.');
        $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
    }

    public function testAWrongCrcFailsTheExtractionOnEveryPhpVersion(): void
    {
        // ZipArchive::extractTo() reports this on PHP 8.4+ only, and a zip stream never reports it: the CRC is ours.
        $zip = $this->root . '/in/crc.zip';
        $this->writeZip($zip, [['name' => 'ok.txt', 'data' => 'ok'], ['name' => 'x', 'data' => 'some data', 'crc' => 123]]);

        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('zip extract failed.', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
    }

    public function testADamagedDeflateStreamFailsTheExtraction(): void
    {
        $zip = $this->root . '/in/deflate.zip';
        $this->writeZip($zip, [['name' => 'x', 'data' => str_repeat('compressible data ', 50_000)]]);
        $bytes = (string) file_get_contents($zip);
        for ($i = 60; $i < 90; ++$i) {
            $bytes[$i] = chr(ord($bytes[$i]) ^ 0xFF);
        }
        file_put_contents($zip, $bytes);

        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('zip extract failed.', $e->getMessage());
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
    }

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function plainAndAesProvider(): iterable
    {
        yield 'plaintext' => [false];
        yield 'AES-256' => [true];
    }

    /**
     * A real backup and a real restore: byte-identical, with the permissions extraction always gave.
     */
    #[DataProvider('plainAndAesProvider')]
    public function testARealBackupRoundTripsByteIdentically(bool $aes): void
    {
        if ($aes) {
            $this->requireAes();
        }
        $project = $this->root . '/project';
        $files   = $this->seedProject($project);
        $source  = [];
        foreach ($files as $relative) {
            $source[$relative] = (string) hash_file('sha256', $project . '/' . $relative);
        }

        $archiver = new BackupArchiver($project, $this->root . '/storage', ['public', 'bin'], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work');
        $password = $aes ? ArchivePassword::fromString(self::PHRASE) : null;
        $options  = new BackupOptions(format: ArchiveFormat::Zip);
        $artifact = $archiver->create($password instanceof ArchivePassword ? $options->withPassword($password) : $options);
        $verified = $archiver->verifyIntegrity($artifact, null, $password);
        self::assertTrue($verified['ok'], implode(' ', $verified['errors']));
        self::assertCount(count($files), $verified['checksums']);

        // The new extraction against ZipArchive::extractTo() on the same archive: content, modes and mtimes.
        $archiver->extractTo($artifact, $this->root . '/new', null, $password);
        $native = new ZipArchive();
        $native->open($artifact->getAbsolutePath(), ZipArchive::RDONLY);
        if ($password instanceof ArchivePassword) {
            $native->setPassword(self::PHRASE);
        }
        self::assertTrue($native->extractTo($this->root . '/native'));
        $native->close();
        self::assertSame($this->tree($this->root . '/native'), $this->tree($this->root . '/new'));
        foreach ($source as $relative => $sha) {
            self::assertSame($sha, hash_file('sha256', $this->root . '/new/' . $relative), $relative);
        }

        if ($aes) {
            return; // The panel restores without a password: an encrypted zip is restored from an import.
        }

        // The whole restore path, over a project that has lost its files.
        foreach ($files as $relative) {
            file_put_contents($project . '/' . $relative, 'overwritten');
        }
        $restore  = new RestoreOrchestrator($project, $archiver, new MemoryRestoreProgressStorage(), []);
        $progress = $restore->restore($artifact);
        self::assertSame('completed', $progress->getPhase(), implode(' ', $progress->getLog()));
        foreach ($source as $relative => $sha) {
            self::assertSame($sha, hash_file('sha256', $project . '/' . $relative), $relative);
        }
    }

    public function testAnImportedEncryptedZipRestoresByteIdentically(): void
    {
        $this->requireAes();
        $project  = $this->root . '/project';
        $files    = $this->seedProject($project);
        $archiver = new BackupArchiver($project, $this->root . '/storage', ['public', 'bin'], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work');
        $password = ArchivePassword::fromString(self::PHRASE);
        $source   = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword($password));

        $imported = $archiver->import($source->getAbsolutePath(), $password);
        self::assertSame(ArchiveFormat::TarGz, $imported->getFormat());
        self::assertSame($source->getChecksums(), $imported->getChecksums());
        $before = [];
        foreach ($files as $relative) {
            self::assertArrayHasKey($relative, $imported->getChecksums());
            $before[$relative] = (string) hash_file('sha256', $project . '/' . $relative);
            file_put_contents($project . '/' . $relative, 'overwritten');
        }

        $progress = (new RestoreOrchestrator($project, $archiver, new MemoryRestoreProgressStorage(), []))->restore($imported);
        self::assertSame('completed', $progress->getPhase(), implode(' ', $progress->getLog()));
        foreach ($before as $relative => $sha) {
            self::assertSame($sha, hash_file('sha256', $project . '/' . $relative), $relative);
        }
    }

    /**
     * @return list<string> project-relative files
     */
    private function seedProject(string $project): array
    {
        (new Filesystem())->mkdir([$project . '/public/deep/er', $project . '/bin']);
        $files = [
            'public/index.php'       => '<?php echo "genuine";',
            'public/deep/er/big.bin' => random_bytes(3 * 1024 * 1024) . str_repeat("\0", 2 * 1024 * 1024),
            'public/empty.txt'       => '',
            'public/ñandú é.txt'     => 'unicode',
            'public/.hidden'         => 'dot file',
            'bin/run.sh'             => "#!/bin/sh\nexit 0\n",
        ];
        foreach ($files as $relative => $content) {
            file_put_contents($project . '/' . $relative, $content);
        }
        chmod($project . '/bin/run.sh', 0o755);
        chmod($project . '/public/index.php', 0o640);
        touch($project . '/public/index.php', 1_000_000_000);

        return array_keys($files);
    }

    private function archiver(): BackupArchiver
    {
        return new BackupArchiver($this->root . '/project', $this->root . '/storage', [], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work', extractLimits: ['max_bytes' => self::LIMIT]);
    }

    private function artifact(string $zip): BackupArtifact
    {
        return new BackupArtifact('poc', 'poc.zip', $zip, new DateTimeImmutable(), (int) filesize($zip), (string) hash_file('sha256', $zip), [], ['format' => 'zip']);
    }

    private function requireAes(): void
    {
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function aesZip(string $path, array $files): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9);
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, self::PHRASE);
        }
        $zip->close();
    }

    /**
     * A valid deflate zip whose headers declare $entry['declared'] as the uncompressed size.
     *
     * @param list<array{name: string, data: string, declared?: int, crc?: int, overlaps?: int}> $entries
     */
    private function writeZip(string $path, array $entries): void
    {
        $body    = '';
        $central = '';
        $offsets = [];
        foreach ($entries as $entry) {
            $name    = $entry['name'];
            $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
            self::assertNotFalse($deflate);
            $compressed = (string) deflate_add($deflate, $entry['data'], ZLIB_FINISH);
            $crc        = $entry['crc'] ?? (int) hexdec(hash('crc32b', $entry['data']));
            $declared   = $entry['declared'] ?? strlen($entry['data']);
            if (isset($entry['overlaps'])) {
                $offset = $offsets[$entry['overlaps']];
            } else {
                $offset = strlen($body);
                $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, 0, 0x5021, $crc, strlen($compressed), $declared, strlen($name), 0) . $name . $compressed;
            }
            $offsets[] = $offset;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 0x031E, 20, 0x0800, 8, 0, 0x5021, $crc, strlen($compressed), $declared, strlen($name), 0, 0, 0, 0, 0o100644 << 16, $offset) . $name;
        }
        file_put_contents($path, $body . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($body), 0));
    }

    /**
     * Rewrites the declared uncompressed size in the local headers and in the central directory.
     */
    private function declare(string $path, int $declared): void
    {
        $bytes = (string) file_get_contents($path);
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $bytes, (int) strrpos($bytes, "PK\x05\x06") + 10);
        $at  = $end['offset'];
        for ($i = 0; $i < $end['count']; ++$i) {
            /** @var array{n: int, e: int, c: int} $lengths */
            $lengths = unpack('vn/ve/vc', $bytes, $at + 28);
            /** @var array{o: int} $local */
            $local = unpack('Vo', $bytes, $at + 42);
            $bytes = substr_replace($bytes, pack('V', $declared), $at + 24, 4);
            $bytes = substr_replace($bytes, pack('V', $declared), $local['o'] + 22, 4);
            $at += 46 + $lengths['n'] + $lengths['e'] + $lengths['c'];
        }
        file_put_contents($path, $bytes);
    }

    /**
     * Names directly inside a directory (a missing directory has none).
     *
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /**
     * @return list<string> every path under $dir, sorted
     */
    private function paths(string $dir): array
    {
        return array_keys($this->tree($dir));
    }

    /**
     * @return array<string, array{type: string, mode: string, mtime: ?int, sha: ?string}>
     */
    private function tree(string $dir): array
    {
        $tree = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $isDir                                                = $file->isDir();
            $tree[substr($file->getPathname(), strlen($dir) + 1)] = [
                'type'  => $isDir ? 'dir' : 'file',
                'mode'  => sprintf('%o', $file->getPerms() & 0o7777),
                'mtime' => $isDir ? null : (int) $file->getMTime(),
                'sha'   => $isDir ? null : (string) hash_file('sha256', $file->getPathname()),
            ];
        }
        ksort($tree);

        return $tree;
    }
}
