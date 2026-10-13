<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use Nowo\SiteBackupBundle\Backup\RuntimeStatePaths;
use PHPUnit\Framework\TestCase;

final class RuntimeStatePathsTest extends TestCase
{
    public function testRelativeToKeepsOnlyPathsInsideTheProject(): void
    {
        self::assertSame(
            ['storage/backups', 'state/progress.json', 'var/history.jsonl', 'relative/setup.json'],
            RuntimeStatePaths::relativeTo('/srv/app/', [
                '/srv/app/storage/backups/',
                '/srv/app/./state/../state/progress.json',
                '/srv/app\\var\\history.jsonl',
                'relative/setup.json',
                '/srv/app/storage/backups',
                '/srv/other/history.jsonl',
                '/srv/application/file.json',
                '/srv/app',
                '',
                '  ',
                null,
            ]),
        );
    }

    public function testRelativeToSupportsWindowsDrives(): void
    {
        self::assertSame(['var/state.json'], RuntimeStatePaths::relativeTo('C:\\app', ['C:\\app\\var\\state.json', 'D:\\app\\x']));
    }

    public function testMatchesExactPathAndChildrenOnly(): void
    {
        $paths = ['storage/backups', 'state/progress.json'];

        self::assertTrue(RuntimeStatePaths::matches('storage/backups', $paths));
        self::assertTrue(RuntimeStatePaths::matches('storage/backups/a.tar.gz', $paths));
        self::assertTrue(RuntimeStatePaths::matches('/state/progress.json', $paths));
        self::assertTrue(RuntimeStatePaths::matches('state\\progress.json', $paths));
        self::assertFalse(RuntimeStatePaths::matches('storage/backups-old/a.tar.gz', $paths));
        self::assertFalse(RuntimeStatePaths::matches('state/progress.json.bak', $paths));
        self::assertFalse(RuntimeStatePaths::matches('config/app.yaml', []));
    }
}
