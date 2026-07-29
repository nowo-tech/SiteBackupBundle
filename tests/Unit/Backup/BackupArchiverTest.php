<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function implode;
use function sys_get_temp_dir;
use function uniqid;

final class BackupArchiverTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        $this->fs->mkdir($this->projectDir . '/public');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
        file_put_contents($this->projectDir . '/public/index.php', "<?php\n");
        file_put_contents($this->projectDir . '/composer.json', "{}\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testCreateVerifyAndList(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config', 'public', 'composer.json'],
            excludePatterns: ['var/cache/*'],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );

        $artifact = $archiver->create('test-label', 'phpunit');
        self::assertFileExists($artifact->getAbsolutePath());
        self::assertNotSame('', $artifact->getArchiveSha256());
        self::assertSame('test-label', $artifact->getLabel());

        $verify = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));
        self::assertNotEmpty($verify['checksums']);

        $list = $archiver->listArtifacts();
        self::assertCount(1, $list);
        self::assertSame($artifact->getId(), $list[0]->getId());

        self::assertTrue($archiver->delete($artifact->getId()));
        self::assertCount(0, $archiver->listArtifacts());
    }

    public function testEmptyIncludePathsMeansEntireProjectMinusExcludes(): void
    {
        $this->fs->mkdir($this->projectDir . '/var/cache');
        $this->fs->mkdir($this->projectDir . '/tmp');
        file_put_contents($this->projectDir . '/var/cache/dev.php', "cached\n");
        file_put_contents($this->projectDir . '/tmp/scratch.txt', "tmp\n");
        file_put_contents($this->projectDir . '/README.md', "hello\n");

        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: [],
            excludePatterns: ['var/*', 'tmp/*'],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );

        $artifact = $archiver->create('full-tree', 'phpunit');
        $verify   = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));

        $checksums = $verify['checksums'];
        self::assertArrayHasKey('config/app.yaml', $checksums);
        self::assertArrayHasKey('public/index.php', $checksums);
        self::assertArrayHasKey('composer.json', $checksums);
        self::assertArrayHasKey('README.md', $checksums);
        self::assertArrayNotHasKey('var/cache/dev.php', $checksums);
        self::assertArrayNotHasKey('tmp/scratch.txt', $checksums);
        foreach (array_keys($checksums) as $path) {
            self::assertStringStartsNotWith('./', $path);
        }
    }

    public function testDotIncludePathMeansEntireProject(): void
    {
        file_put_contents($this->projectDir . '/root.txt', "root\n");

        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['.'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );

        $artifact = $archiver->create('dot-root', 'phpunit');
        $verify   = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));
        self::assertArrayHasKey('root.txt', $verify['checksums']);
        self::assertArrayHasKey('config/app.yaml', $verify['checksums']);
        self::assertArrayNotHasKey('./root.txt', $verify['checksums']);
    }
}
