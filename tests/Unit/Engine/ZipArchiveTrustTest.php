<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Backup\ZipAesAuthenticator;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\UnsafeArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function array_diff;
use function array_values;
use function chr;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function function_exists;
use function hash_file;
use function hrtime;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function ord;
use function pack;
use function scandir;
use function str_repeat;
use function strlen;
use function strrpos;
use function substr;
use function substr_replace;
use function symlink;
use function unpack;

/**
 * Audit HK: the AES authentication covers exactly what libzip extracts, a zip extraction leaves what it did not create
 * alone, a password refuses plaintext on every path, and bytes that fail their SHA-256 are never unpacked.
 */
final class ZipArchiveTrustTest extends TestCase
{
    private const PHRASE = 'canary-zip-trust-phrase-00';

    private string $root;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
        $this->root = EngineFixtures::tempDir('sbb-ziptrust-');
        (new Filesystem())->mkdir([$this->root . '/project', $this->root . '/in', $this->root . '/work']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function plantedCommentProvider(): iterable
    {
        yield 'a record with no entries (audit probe plant_count0)' => [str_repeat('Z', 8) . pack('VvvvvVVv', 0x06054B50, 0, 0, 0, 0, 0, 0, 0)];
        yield 'a record pointing at offset 0 (audit probe plant_badoffset)' => [str_repeat('Z', 8) . pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, 46, 0, 0)];
        yield 'only the signature' => ["PK\x05\x06"];
        yield 'a record that claims another disk' => [pack('VvvvvVVv', 0x06054B50, 1, 1, 0, 0, 0, 0, 0)];
    }

    #[DataProvider('plantedCommentProvider')]
    public function testACommentHoldingAnotherEndRecordIsRefusedOnEveryPath(string $comment): void
    {
        $zip = $this->root . '/in/planted.zip';
        $this->aesZip($zip);
        $this->setComment($zip, $comment);

        // libzip still opens it, with the real entries: this is the disagreement.
        $check = new ZipArchive();
        self::assertTrue($check->open($zip, ZipArchive::RDONLY));
        self::assertSame(2, $check->numFiles);
        $check->close();

        try {
            ZipAesAuthenticator::verify($zip, $this->password());
            self::fail('verify() must refuse.');
        } catch (CorruptArchiveException $e) {
            self::assertSame(CorruptArchiveException::CHECKSUM, $e->getCode());
        }

        self::assertSame(VerifyFileResult::INTEGRITY, $this->archiver()->verifyFile($zip, $this->password())->status);
        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest', null, $this->password());
            self::fail('extractTo() must refuse.');
        } catch (CorruptArchiveException) {
        }
        self::assertSame([], $this->entries($this->root . '/dest'));
        self::assertFalse($this->archiver()->verifyIntegrity($this->artifact($zip), null, $this->password())['ok']);
        try {
            $this->archiver()->import($zip, $this->password());
            self::fail('import() must refuse.');
        } catch (CorruptArchiveException) {
        }
    }

    public function testATamperedEntryBehindAPlantedRecordIsRefused(): void
    {
        $zip = $this->root . '/in/tampered.zip';
        $this->aesZip($zip);
        $bytes = (string) file_get_contents($zip);
        // The first entry's ciphertext: 30 + 5 (name) + 11 (AES field) + 16 (salt) + 2 (verifier) = 64.
        $bytes[66] = chr(ord($bytes[66]) ^ 0x01);
        file_put_contents($zip, $bytes);
        $this->setComment($zip, pack('VvvvvVVv', 0x06054B50, 0, 0, 0, 0, 0, 0, 0));

        $this->expectException(CorruptArchiveException::class);
        ZipAesAuthenticator::verify($zip, $this->password());
    }

    public function testTwoDirectoriesInOneFileAreRefused(): void
    {
        $zip = $this->root . '/in/two.zip';
        $this->aesZip($zip);
        $bytes = (string) file_get_contents($zip);
        $eocd  = (int) strrpos($bytes, "PK\x05\x06");
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $bytes, $eocd + 10);
        // The same directory again after the first one, with its own end record and no comment involved.
        $second = substr($bytes, $end['offset'], $end['size']);
        $record = pack('VvvvvVVv', 0x06054B50, 0, 0, $end['count'], $end['count'], $end['size'], strlen($bytes), 0);
        file_put_contents($zip, $bytes . $second . $record);

        $this->expectException(CorruptArchiveException::class);
        $this->expectExceptionMessage('more than one end of central directory record');
        ZipAesAuthenticator::verify($zip, $this->password());
    }

    public function testDataAfterTheEndRecordIsRefused(): void
    {
        $zip = $this->root . '/in/trailing.zip';
        $this->aesZip($zip);
        file_put_contents($zip, (string) file_get_contents($zip) . 'trailing');

        $this->expectException(CorruptArchiveException::class);
        $this->expectExceptionMessage('data after the end of central directory record');
        ZipAesAuthenticator::verify($zip, $this->password());
    }

    public function testAnOrdinaryCommentIsFine(): void
    {
        $zip = $this->root . '/in/comment.zip';
        $this->aesZip($zip);
        $this->setComment($zip, 'made by another tool');

        ZipAesAuthenticator::verify($zip, $this->password());
        $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest', null, $this->password());
        self::assertSame('first', file_get_contents($this->root . '/dest/a.txt'));
    }

    public function testVerifyPassingMeansEveryEntryWasAuthenticated(): void
    {
        $plain = $this->root . '/in/plain.zip';
        $zip   = new ZipArchive();
        $zip->open($plain, ZipArchive::CREATE);
        $zip->addFromString('a.txt', 'plain');
        $zip->close();
        try {
            ZipAesAuthenticator::verify($plain, $this->password());
            self::fail('A plaintext entry is not authenticated.');
        } catch (CorruptArchiveException $e) {
            self::assertStringContainsString('only AES-256 entries are accepted', $e->getMessage());
        }

        $empty = $this->root . '/in/empty.zip';
        file_put_contents($empty, pack('VvvvvVVv', 0x06054B50, 0, 0, 0, 0, 0, 0, 0));
        try {
            ZipAesAuthenticator::verify($empty, $this->password());
            self::fail('Nothing was authenticated.');
        } catch (CorruptArchiveException $e) {
            self::assertStringContainsString('no entries', $e->getMessage());
        }
    }

    public function testEntriesThatDeclareNoDataAreAuthenticatedToo(): void
    {
        $zip = $this->root . '/in/empty-entry.zip';
        $this->aesZip($zip);
        ZipAesAuthenticator::verify($zip, $this->password());

        // libzip reads nothing for a declared size of 0, so this hides the entry from any check that goes through it.
        $bytes = (string) file_get_contents($zip);
        /** @var array{count: int, size: int, offset: int} $end */
        $end    = unpack('vcount/Vsize/Voffset', $bytes, (int) strrpos($bytes, "PK\x05\x06") + 10);
        $hidden = substr_replace($bytes, pack('V', 0), $end['offset'] + 24, 4);
        $path   = $this->root . '/in/hidden.zip';
        file_put_contents($path, $hidden);
        ZipAesAuthenticator::verify($path, $this->password());

        $tampered = $hidden;
        // The first entry's ciphertext: 30 + 5 (name) + 11 (AES field) + 16 (salt) + 2 (verifier) = 64.
        $tampered[66] = chr(ord($tampered[66]) ^ 0x01);
        file_put_contents($path, $tampered);
        try {
            ZipAesAuthenticator::verify($path, $this->password());
            self::fail('A tampered entry that declares size 0 must fail.');
        } catch (CorruptArchiveException $e) {
            self::assertStringContainsString('tampered or truncated', $e->getMessage());
        }

        file_put_contents($path, $hidden);
        try {
            ZipAesAuthenticator::verify($path, ArchivePassword::fromString('another-phrase-0000-0000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }

        // A real empty file, through the real paths.
        $real = $this->root . '/in/real-empty.zip';
        $zipA = new ZipArchive();
        $zipA->open($real, ZipArchive::CREATE);
        $zipA->addFromString('empty.txt', '');
        $zipA->setEncryptionName('empty.txt', ZipArchive::EM_AES_256, self::PHRASE);
        $zipA->close();
        $this->archiver()->extractTo($this->artifact($real), $this->root . '/dest', null, $this->password());
        self::assertSame('', file_get_contents($this->root . '/dest/empty.txt'));
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function brokenDirectoryProvider(): iterable
    {
        yield 'a local header offset that is no header' => [42, "\x01\x00\x00\x00", 'bad local header'];
        yield 'an offset outside the file' => [42, "\xF0\xFF\xFF\x7F", ''];
    }

    #[DataProvider('brokenDirectoryProvider')]
    public function testAnEmptyEntryWithABrokenDirectoryIsRefused(int $field, string $value, string $reason): void
    {
        $zip = $this->root . '/in/broken.zip';
        $one = new ZipArchive();
        $one->open($zip, ZipArchive::CREATE);
        $one->addFromString('empty.txt', '');
        $one->setEncryptionName('empty.txt', ZipArchive::EM_AES_256, self::PHRASE);
        $one->close();
        $bytes = (string) file_get_contents($zip);
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $bytes, (int) strrpos($bytes, "PK\x05\x06") + 10);
        file_put_contents($zip, substr_replace($bytes, $value, $end['offset'] + $field, 4));

        try {
            ZipAesAuthenticator::verify($zip, $this->password());
            self::fail('Expected a refusal');
        } catch (CorruptArchiveException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * libzip refuses these first when it opens the archive: the parser's own checks are the second line.
     */
    public function testTheDirectoryParserRefusesWhatDoesNotMatch(): void
    {
        $a = $this->root . '/in/a.zip';
        $this->aesZip($a);
        $other = $this->root . '/in/other.zip';
        $this->plainZip($other, ['x.txt' => 'x', 'y.txt' => 'y']);
        $one = $this->root . '/in/one.zip';
        $this->plainZip($one, ['x.txt' => 'x']);
        $zip = new ZipArchive();
        $zip->open($a, ZipArchive::RDONLY);
        $missing = $this->root . '/in/missing.zip';

        $this->assertParserRefuses('unable to open', 'openEmptyEntry', $missing, ['name' => 'x', 'offset' => 0]);
        $this->assertParserRefuses('unable to open', 'directory', $missing, $zip);
        $this->assertParserRefuses('does not match', 'directory', $one, $zip);
        $this->assertParserRefuses('does not match', 'directory', $other, $zip);

        $bytes = (string) file_get_contents($a);
        /** @var array{count: int, size: int, offset: int} $end */
        $end    = unpack('vcount/Vsize/Voffset', $bytes, (int) strrpos($bytes, "PK\x05\x06") + 10);
        $broken = $this->root . '/in/broken.zip';
        file_put_contents($broken, substr_replace($bytes, 'XXXX', $end['offset'], 4));
        $this->assertParserRefuses('bad central directory entry', 'directory', $broken, $zip);
        $zip->close();

        $zip64End = pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0);
        file_put_contents($broken, str_repeat('.', 40) . $zip64End);
        $handle = fopen($broken, 'r');
        self::assertIsResource($handle);
        $this->assertParserRefuses('no zip64 locator', 'centralDirectory', $handle);
        fclose($handle);
        file_put_contents($broken, str_repeat('.', 80) . "PK\x06\x07" . pack('V', 0) . pack('P', 0) . pack('V', 1) . $zip64End);
        $handle = fopen($broken, 'r');
        self::assertIsResource($handle);
        $this->assertParserRefuses('no zip64 end of central directory', 'centralDirectory', $handle);
        $this->assertParserRefuses('offset outside the archive', 'readAt', $handle, -1, 1);
        fclose($handle);

        $this->assertParserRefuses('missing zip64 extra field', 'zip64Values', pack('vv', 0x0002, 2) . 'ab', true, 1, 1);
        $this->assertParserRefuses('short zip64 field', 'uint64', 'short', 0);
        $this->assertParserRefuses('zip64 value out of range', 'uint64', str_repeat("\xFF", 8), 0);
    }

    public function testAnExistingFileIsNeitherOverwrittenNorDeleted(): void
    {
        $zip = $this->root . '/in/over.zip';
        $this->plainZip($zip, ['new.txt' => 'new', 'dir/keep.txt' => 'from the archive']);
        mkdir($this->root . '/dest/dir', 0o777, true);
        file_put_contents($this->root . '/dest/dir/keep.txt', 'precious');

        try {
            $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('zip extract failed.', $e->getMessage());
        }
        self::assertSame('precious', file_get_contents($this->root . '/dest/dir/keep.txt'));
        self::assertSame(['dir'], $this->entries($this->root . '/dest'), 'new.txt was this call\'s and is gone.');
    }

    public function testNothingIsWrittenThroughALink(): void
    {
        file_put_contents($this->root . '/victim.txt', 'victim');
        mkdir($this->root . '/victim-dir');
        mkdir($this->root . '/dest');
        symlink($this->root . '/victim.txt', $this->root . '/dest/file-link');
        symlink($this->root . '/victim-dir', $this->root . '/dest/dir-link');

        foreach (['file-link', 'dir-link/out.txt', 'dir-link/sub/out.txt'] as $name) {
            $zip = $this->root . '/in/link.zip';
            $this->plainZip($zip, [$name => 'through the link'], true);
            try {
                $this->archiver()->extractTo($this->artifact($zip), $this->root . '/dest');
                self::fail('Expected RuntimeException for ' . $name);
            } catch (RuntimeException $e) {
                self::assertSame('zip extract failed.', $e->getMessage());
            }
            self::assertSame('victim', file_get_contents($this->root . '/victim.txt'));
            self::assertSame([], $this->entries($this->root . '/victim-dir'));
            self::assertTrue(is_link($this->root . '/dest/file-link'), 'The link was not this call\'s to remove.');
            self::assertTrue(is_link($this->root . '/dest/dir-link'));
        }
    }

    public function testAnExistingDirectoryIsUsedAndKept(): void
    {
        $bad = $this->root . '/in/bad.zip';
        $this->plainZip($bad, ['here/one.txt' => 'one', 'here/one.txt/under' => 'cannot be']);
        mkdir($this->root . '/dest/here', 0o777, true);

        try {
            $this->archiver()->extractTo($this->artifact($bad), $this->root . '/dest');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        self::assertTrue(is_dir($this->root . '/dest/here'));
        self::assertSame([], $this->entries($this->root . '/dest/here'));
    }

    public function testAPasswordRefusesPlaintextOnEveryPath(): void
    {
        $zip = $this->root . '/in/plain.zip';
        $this->plainZip($zip, ['MANIFEST.json' => '{"checksums":{}}']);
        $tar      = $this->root . '/in/plain.tar.gz';
        $archiver = $this->archiver();
        $stored   = $archiver->create();
        file_put_contents($tar, (string) file_get_contents($stored->getAbsolutePath()));
        $artifacts = [
            'zip'    => $this->artifact($zip),
            'tar.gz' => new BackupArtifact('t', 'plain.tar.gz', $tar, new DateTimeImmutable(), (int) filesize($tar), (string) hash_file('sha256', $tar), [], ['format' => 'tar.gz']),
        ];

        foreach ($artifacts as $format => $artifact) {
            try {
                $archiver->extractTo($artifact, $this->root . '/dest-' . $format, null, $this->password());
                self::fail('extractTo() must refuse ' . $format);
            } catch (CorruptArchiveException $e) {
                self::assertSame(CorruptArchiveException::NOT_ENCRYPTED, $e->getCode());
            }
            self::assertSame([], $this->entries($this->root . '/dest-' . $format));

            $refused = $archiver->verifyIntegrity($artifact, null, $this->password());
            self::assertFalse($refused['ok'], $format);
            self::assertStringContainsString('not encrypted', implode(' ', $refused['errors']));

            // The explicit way, and the way with no password at all, still work.
            self::assertTrue($archiver->verifyIntegrity($artifact, null, $this->password(), true)['ok'], $format);
            self::assertTrue($archiver->verifyIntegrity($artifact)['ok'], $format);
            $archiver->extractTo($artifact, $this->root . '/ok-' . $format, null, $this->password(), true);
            self::assertTrue(is_file($this->root . '/ok-' . $format . '/MANIFEST.json'));
        }
    }

    public function testBytesThatFailTheirHashAreNotUnpacked(): void
    {
        // Unpacking this one would add its own error: the name check refuses it.
        $zip = $this->root . '/in/slip.zip';
        $this->plainZip($zip, ['a/../../evil.txt' => 'x']);
        $artifact = new BackupArtifact('h', 'slip.zip', $zip, new DateTimeImmutable(), (int) filesize($zip), str_repeat('0', 64), [], ['format' => 'zip']);

        $result = $this->archiver()->verifyIntegrity($artifact);

        self::assertFalse($result['ok']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('SHA-256 mismatch', $result['errors'][0]);
        self::assertSame([], $this->entries($this->root . '/work'));
    }

    public function testBytesThatFailTheirSignatureAreNotUnpacked(): void
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('ext-sodium');
        }
        $zip = $this->root . '/in/slip.zip';
        $this->plainZip($zip, ['a/../../evil.txt' => 'x']);
        $pair     = BackupSigner::generateKeyPair();
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', [], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work', signer: new BackupSigner($pair['secret_key'], 'k1'));
        $sha      = (string) hash_file('sha256', $zip);

        $forged = $archiver->verifyIntegrity(new BackupArtifact('s', 'slip.zip', $zip, new DateTimeImmutable(), (int) filesize($zip), $sha, [], ['format' => 'zip', 'signature' => 'forged', 'signing_key_id' => 'k1']));
        self::assertCount(1, $forged['errors']);
        self::assertStringContainsString('Signature invalid', $forged['errors'][0]);

        $unsigned = $archiver->verifyIntegrity(new BackupArtifact('s', 'slip.zip', $zip, new DateTimeImmutable(), (int) filesize($zip), $sha, [], ['format' => 'zip']));
        self::assertCount(1, $unsigned['errors']);
        self::assertStringContainsString('Signature missing', $unsigned['errors'][0]);
    }

    public function testATarListingOverTheLimitStopsTarAtOnce(): void
    {
        // Lists more than the limit, then hangs: the old code waited for the whole listing.
        $tar = EngineFixtures::script($this->root, 'slow-tar', <<<'SH'
            i=0; while [ $i -lt 50 ]; do echo "-rw-r--r-- u/g 1 2026-01-01 00:00 f$i"; i=$((i+1)); done
            exec sleep 30
            SH);
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', [], [], null, 20, clock: new MockClock(), workDir: $this->root . '/work', tarBinary: $tar, extractLimits: ['max_entries' => 10]);
        $artifact = new BackupArtifact('l', 'l.tar.gz', __FILE__, new DateTimeImmutable(), 1, 'x', [], ['format' => 'tar.gz']);

        $started = hrtime(true);
        try {
            $archiver->extractTo($artifact, $this->root . '/dest');
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException $e) {
            self::assertStringContainsString('more entries', $e->getMessage());
        }
        self::assertLessThan(10.0, (hrtime(true) - $started) / 1e9);
    }

    public function testAnImportListingOverTheLimitIsRefusedBeforeItEnds(): void
    {
        $tar = EngineFixtures::script($this->root, 'slow-tar', <<<'SH'
            i=0; while [ $i -lt 50 ]; do echo "-rw-r--r-- u/g 1 2026-01-01 00:00 f$i"; i=$((i+1)); done
            exec sleep 30
            SH);
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', [], [], null, 20, clock: new MockClock(), workDir: $this->root . '/work', tarBinary: $tar, extractLimits: ['max_entries' => 10]);
        $real     = new BackupArchiver($this->root . '/project', $this->root . '/real', [], [], null, 20, clock: new MockClock(), workDir: $this->root . '/work');
        file_put_contents($this->root . '/project/a.txt', 'a');

        $started = hrtime(true);
        try {
            $archiver->import($real->create()->getAbsolutePath());
            self::fail('Expected UnsafeArchiveException');
        } catch (UnsafeArchiveException $e) {
            self::assertStringContainsString('more entries', $e->getMessage());
        }
        self::assertLessThan(10.0, (hrtime(true) - $started) / 1e9);
    }

    private function assertParserRefuses(string $reason, string $method, mixed ...$arguments): void
    {
        try {
            (new ReflectionMethod(ZipAesAuthenticator::class, $method))->invoke(null, ...$arguments);
            self::fail('Expected a refusal from ' . $method);
        } catch (CorruptArchiveException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    private function archiver(): BackupArchiver
    {
        return new BackupArchiver($this->root . '/project', $this->root . '/storage', ['.'], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work');
    }

    private function password(): ArchivePassword
    {
        return ArchivePassword::fromString(self::PHRASE);
    }

    private function artifact(string $zip): BackupArtifact
    {
        return new BackupArtifact('z', 'z.zip', $zip, new DateTimeImmutable(), (int) filesize($zip), (string) hash_file('sha256', $zip), [], ['format' => 'zip']);
    }

    private function aesZip(string $path): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach (['a.txt' => 'first', 'b.txt' => str_repeat('second ', 100)] as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, self::PHRASE);
        }
        $zip->close();
    }

    /**
     * @param array<string, string> $files
     */
    private function plainZip(string $path, array $files, bool $overwrite = false): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ($overwrite ? ZipArchive::OVERWRITE : 0));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /**
     * Sets the archive comment of a zip that has none, in the raw bytes.
     */
    private function setComment(string $path, string $comment): void
    {
        $bytes = (string) file_get_contents($path);
        $eocd  = (int) strrpos($bytes, "PK\x05\x06");
        file_put_contents($path, substr($bytes, 0, $eocd + 20) . pack('v', strlen($comment)) . $comment);
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }
}
