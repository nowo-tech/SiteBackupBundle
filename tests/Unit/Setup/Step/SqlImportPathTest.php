<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Step;

use Nowo\SiteBackupBundle\Setup\Step\SqlImportPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SqlImportPathTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-sqlpath-' . uniqid('', true);
        $this->fs->dumpFile($this->dir . '/var/site-backup/dump.SQL', 'SELECT 1;');
        $this->fs->dumpFile($this->dir . '/var/site-backup/notes.txt', 'x');
        $this->fs->dumpFile($this->dir . '/var/other.sql', 'SELECT 1;');
        $this->fs->symlink($this->dir . '/var/other.sql', $this->dir . '/var/site-backup/link.sql');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testResolvesSqlFilesInsideSiteBackupDirOnly(): void
    {
        $expected = realpath($this->dir . '/var/site-backup/dump.SQL');
        self::assertSame($expected, SqlImportPath::resolve($this->dir, 'var/site-backup/dump.SQL'));
        self::assertSame($expected, SqlImportPath::resolve($this->dir . '/', '/' . ltrim($this->dir, '/') . '/var/site-backup/dump.SQL'));

        self::assertNull(SqlImportPath::resolve($this->dir, ''));
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/site-backup/notes.txt'));
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/other.sql'));
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/site-backup/../other.sql'));
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/site-backup/link.sql'), 'symlink escaping the dir');
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/site-backup'));
        self::assertNull(SqlImportPath::resolve($this->dir, 'var/site-backup/dump.SQL', 'var/missing-dir'));
    }
}
