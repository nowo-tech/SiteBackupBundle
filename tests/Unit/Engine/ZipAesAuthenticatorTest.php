<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Backup\ZipAesAuthenticator;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use ZipArchive;

use function chr;
use function file_get_contents;
use function file_put_contents;
use function ord;
use function pack;
use function strlen;
use function strrpos;
use function substr;
use function unpack;

/**
 * 1.29.1: every byte of an AES-256 zip entry is authenticated, whatever the PHP version (`extractTo()` on PHP < 8.4
 * ignores libzip's HMAC errors, so a flipped ciphertext byte used to verify as OK).
 */
final class ZipAesAuthenticatorTest extends TestCase
{
    private const PHRASE = 'canary-zip-aes-phrase-0000';

    private string $root;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
        $this->root = EngineFixtures::tempDir('sbb-zipaes-');
        (new Filesystem())->mkdir([$this->root . '/project/public', $this->root . '/in']);
        file_put_contents($this->root . '/project/public/index.php', '<?php echo "genuine";');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testEveryByteOfEveryEncryptedEntryIsAuthenticated(): void
    {
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60, clock: new MockClock(), workDir: $this->root . '/work');
        $zip      = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString(self::PHRASE)));
        $bytes    = (string) file_get_contents($zip->getAbsolutePath());
        self::assertSame(VerifyFileResult::OK, $archiver->verifyFile($zip->getAbsolutePath(), ArchivePassword::fromString(self::PHRASE))->status);

        $entries = self::entries($bytes);
        self::assertCount(2, $entries, 'MANIFEST.json and public/index.php');
        foreach ($entries as $name => [$data, $size]) {
            // salt (16) + password verifier (2) | ciphertext | HMAC (10)
            for ($at = $data; $at < $data + $size; ++$at) {
                $tampered      = $bytes;
                $tampered[$at] = chr(ord($tampered[$at]) ^ 0x01);
                file_put_contents($this->root . '/in/t.zip', $tampered);
                $status = $archiver->verifyFile($this->root . '/in/t.zip', ArchivePassword::fromString(self::PHRASE))->status;
                $where  = $name . ' +' . ($at - $data);
                if ($at < $data + 18) {
                    self::assertContains($status, [VerifyFileResult::INTEGRITY, VerifyFileResult::WRONG_PASSWORD], $where);
                } else {
                    self::assertSame(VerifyFileResult::INTEGRITY, $status, $where);
                }
            }
        }
    }

    public function testZip64LayoutAndBrokenStructures(): void
    {
        $plain = $this->root . '/in/a.zip';
        $zip   = new ZipArchive();
        $zip->open($plain, ZipArchive::CREATE);
        $zip->addFromString('a.json', '{"a":1}');
        $zip->setEncryptionName('a.json', ZipArchive::EM_AES_256, self::PHRASE);
        $zip->addFromString('b.txt', 'b');
        $zip->setEncryptionName('b.txt', ZipArchive::EM_AES_256, self::PHRASE);
        $zip->close();
        $bytes = (string) file_get_contents($plain);
        $good  = ArchivePassword::fromString(self::PHRASE);

        ZipAesAuthenticator::verify($plain, $good);
        $zip64 = self::toZip64($bytes);
        file_put_contents($this->root . '/in/z64.zip', $zip64);
        ZipAesAuthenticator::verify($this->root . '/in/z64.zip', $good);

        [$data, $size] = self::entries($bytes)['b.txt'];
        $flipped       = $zip64;
        $at            = $data + $size - 1;
        $flipped[$at]  = chr(ord($flipped[$at]) ^ 0x01);
        $this->assertFails($flipped, CorruptArchiveException::class, 'tampered or truncated');

        try {
            ZipAesAuthenticator::verify($plain, ArchivePassword::fromString('another-phrase-0000-0000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException) {
        }

        $eocd = (int) strrpos($bytes, "PK\x05\x06");
        $this->assertFails('not a zip at all', CorruptArchiveException::class, 'no end of central directory');
        $this->assertFails(substr($bytes, 0, $data + 20) . substr($bytes, $eocd), CorruptArchiveException::class, 'truncated archive');
        $lastEntry = (int) strrpos($bytes, "PK\x01\x02");
        $this->assertFails(self::patch($bytes, $lastEntry + 20, pack('V', 0x7FFFFFF0)), CorruptArchiveException::class, 'truncated entry');
        $this->assertFails(self::patch($bytes, $lastEntry + 20, pack('V', 5)), CorruptArchiveException::class, 'entry too short');
        $this->assertFails(self::patch($bytes, $lastEntry + 24, pack('V', 0xFFFFFFFF)), CorruptArchiveException::class, 'missing zip64 extra field');
        $aesField = (int) strrpos($bytes, pack('v', 0x9901) . pack('v', 7));
        $this->assertFails(self::patch($bytes, $aesField, pack('v', 0x9902)), CorruptArchiveException::class, 'only AES-256 entries are accepted');
        // The ZIP64 extra field of the last entry (b.txt) claims 8 bytes: the compressed size is missing.
        $zip64Entry = (int) strrpos($zip64, "PK\x01\x02");
        $this->assertFails(self::patch($zip64, $zip64Entry + 46 + 5 + 2, pack('v', 8)), CorruptArchiveException::class, 'short zip64 field');
        $record = (int) strrpos($zip64, "PK\x06\x06");
        $this->assertFails(self::patch($zip64, $record, 'XXXX'), CorruptArchiveException::class, 'no zip64 end of central directory');
        $this->assertFails(self::patch($zip64, $record + 52, pack('V', 0x80000000)), CorruptArchiveException::class, 'zip64 value out of range');
        $this->assertFails(substr($zip64, 0, -98) . substr($zip64, -22), CorruptArchiveException::class, 'no zip64 locator');
        $this->assertFails(self::patch($bytes, $eocd + 16, pack('V', 0)), CorruptArchiveException::class, 'bad central directory entry');
        $this->assertFails(self::patch($bytes, (int) strrpos($bytes, "PK\x01\x02") + 42, pack('V', 1)), CorruptArchiveException::class, 'bad local header');

        $short = $this->root . '/in/short.zip';
        $zip   = new ZipArchive();
        $zip->open($short, ZipArchive::CREATE);
        $zip->addFromString('c.txt', 'c');
        $zip->setEncryptionName('c.txt', ZipArchive::EM_AES_128, self::PHRASE);
        $zip->close();
        $this->assertFails((string) file_get_contents($short), CorruptArchiveException::class, 'only AES-256 entries are accepted');

        try {
            ZipAesAuthenticator::verify($this->root . '/in/missing.zip', $good);
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException $e) {
            self::assertSame(CorruptArchiveException::CHECKSUM, $e->getCode());
        }
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function assertFails(string $bytes, string $class, string $reason): void
    {
        file_put_contents($this->root . '/in/bad.zip', $bytes);
        try {
            ZipAesAuthenticator::verify($this->root . '/in/bad.zip', ArchivePassword::fromString(self::PHRASE));
            self::fail('Expected ' . $class . ': ' . $reason);
        } catch (CorruptArchiveException $e) {
            self::assertInstanceOf($class, $e);
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * Entry name => [offset of the AES payload (salt), its size], from the local headers.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function entries(string $bytes): array
    {
        $entries = [];
        $offset  = 0;
        while (substr($bytes, $offset, 4) === "PK\x03\x04") {
            /** @var array{c: int, n: int, e: int} $h */
            $h    = unpack('Vc', $bytes, $offset + 18) + unpack('vn/ve', $bytes, $offset + 26);
            $data = $offset + 30 + $h['n'] + $h['e'];
            self::assertGreaterThan(28, $h['c'], 'sizes are in the local header');
            $entries[substr($bytes, $offset + 30, $h['n'])] = [$data, $h['c']];
            $offset                                         = $data + $h['c'];
        }

        return $entries;
    }

    /**
     * Rewrites the central directory in ZIP64 form (sizes and offsets in the 0x0001 extra field, ZIP64 end records).
     */
    private static function toZip64(string $bytes): string
    {
        $eocd = (int) strrpos($bytes, "PK\x05\x06");
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $bytes, $eocd + 10);
        $cd  = '';
        $at  = $end['offset'];
        for ($i = 0; $i < $end['count']; ++$i) {
            /** @var array{comp: int, uncomp: int, n: int, e: int, c: int, offset: int} $h */
            $h     = unpack('Vcomp/Vuncomp/vn/ve/vc', $bytes, $at + 20) + unpack('Voffset', $bytes, $at + 42);
            $zip64 = pack('vv', 0x0001, 24) . pack('P', $h['uncomp']) . pack('P', $h['comp']) . pack('P', $h['offset']);
            $cd .= substr($bytes, $at, 20) . pack('VV', 0xFFFFFFFF, 0xFFFFFFFF)
                . pack('vvv', $h['n'], $h['e'] + strlen($zip64), $h['c']) . substr($bytes, $at + 34, 8) . pack('V', 0xFFFFFFFF)
                . substr($bytes, $at + 46, $h['n']) . $zip64 . substr($bytes, $at + 46 + $h['n'], $h['e'] + $h['c']);
            $at += 46 + $h['n'] + $h['e'] + $h['c'];
        }
        $cdOffset = $end['offset'];
        $record   = "PK\x06\x06" . pack('P', 44) . pack('vvVV', 45, 45, 0, 0) . pack('PPPP', $end['count'], $end['count'], strlen($cd), $cdOffset);
        $locator  = "PK\x06\x07" . pack('V', 0) . pack('P', $cdOffset + strlen($cd)) . pack('V', 1);

        return substr($bytes, 0, $cdOffset) . $cd . $record . $locator
            . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0);
    }

    private static function patch(string $bytes, int $at, string $with): string
    {
        return substr($bytes, 0, $at) . $with . substr($bytes, $at + strlen($with));
    }
}
