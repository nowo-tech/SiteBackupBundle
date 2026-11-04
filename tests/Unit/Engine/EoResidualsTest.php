<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function chmod;
use function getenv;
use function mkdir;
use function putenv;
use function realpath;

/**
 * EO re-audit residuals (1.29.0): EO-R4, the age binary is resolved once to a trusted absolute path.
 */
final class EoResidualsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-eor-');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testRelativeAndWorldWritableBinariesAreRefused(): void
    {
        $this->assertRefused(new AgeBinaryEncryptor('bin/age'), 'is relative: use an absolute path');

        mkdir($this->root . '/open');
        $age = EngineFixtures::fakeAge($this->root . '/open');
        chmod($age, 0o755);
        chmod($this->root . '/open', 0o777);
        $this->assertRefused(new AgeBinaryEncryptor($age), 'world-writable');

        chmod($this->root . '/open', 0o755);
        chmod($age, 0o777);
        $this->assertRefused(new AgeBinaryEncryptor($age), 'world-writable');

        chmod($age, 0o755);
        (new AgeBinaryEncryptor($age))->assertAvailable();
    }

    public function testABareNameIsResolvedOnceAndLogged(): void
    {
        mkdir($this->root . '/bin');
        $age = (string) realpath(EngineFixtures::fakeAge($this->root . '/bin'));
        chmod($this->root . '/bin', 0o755);
        chmod($age, 0o755);
        $logger = new RecordingLogger();

        $path = getenv('PATH');
        // @phpstan-ignore-next-line (test process only, restored below)
        putenv('PATH=' . $this->root . '/bin');
        try {
            $encryptor = new AgeBinaryEncryptor('age', logger: $logger);
        } finally {
            // @phpstan-ignore-next-line
            putenv('PATH=' . $path);
        }

        // PATH no longer matters: the path resolved at build time is used.
        $encryptor->assertAvailable();
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame(['configured' => 'age', 'resolved' => $age], $logger->records[0]['context']);

        $quiet = new RecordingLogger();
        (new AgeBinaryEncryptor($age, logger: $quiet))->assertAvailable();
        self::assertSame([], $quiet->records, 'an absolute path is used silently');
    }

    private function assertRefused(AgeBinaryEncryptor $encryptor, string $reason): void
    {
        try {
            $encryptor->assertAvailable();
            self::fail('Expected FormatUnavailableException: ' . $reason);
        } catch (FormatUnavailableException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }
}
