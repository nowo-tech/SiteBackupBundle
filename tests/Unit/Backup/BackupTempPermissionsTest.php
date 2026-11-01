<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function sprintf;

/**
 * REQ-SEC-004 CG L1: the staging tree of a backup (plaintext DB dump + .env) is owner-only while it exists, under an
 * unpredictable name, and the dump / manifest are 0600 inside the archive.
 */
final class BackupTempPermissionsTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;

    protected function setUp(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Needs /proc to inspect the staging tree from the dump command.');
        }

        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-perm-proj-' . bin2hex(random_bytes(6));
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-perm-store-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
        file_put_contents($this->projectDir . '/.env', "APP_SECRET=not-a-real-secret\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->projectDir, $this->storageDir]);
    }

    public function testStagingTreeIsOwnerOnlyAndUnpredictable(): void
    {
        // The dump command runs while the staging tree exists: it reports the modes of the dump file and of its
        // parents (resolved from its own stdout, the dump file) into the dump itself.
        $probe    = 'sh -c \'p=$(readlink /proc/$$/fd/1); stat -c "%a %n" "$p" "${p%/*}" "${p%/*/*}" "${p%/*/*/*}"\'';
        $artifact = (new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config', '.env'],
            excludePatterns: [],
            databaseDumpCommand: $probe,
            processTimeoutSeconds: 60,
        ))->create('perm', 'phpunit');

        $dump = new Process(['tar', '-xzOf', $artifact->getAbsolutePath(), './database/dump.sql']);
        $dump->mustRun();
        $lines = array_values(array_filter(explode("\n", trim($dump->getOutput()))));
        self::assertCount(4, $lines, $dump->getOutput());

        $root = preg_quote(rtrim(sys_get_temp_dir(), '/'), '#') . '/nowo-site-backup-' . preg_quote($artifact->getId(), '#') . '-[0-9a-f]{32}';
        self::assertMatchesRegularExpression('#^600 ' . $root . '/payload/database/dump\.sql$#', $lines[0]);
        self::assertMatchesRegularExpression('#^700 ' . $root . '/payload/database$#', $lines[1]);
        self::assertMatchesRegularExpression('#^700 ' . $root . '/payload$#', $lines[2]);
        self::assertMatchesRegularExpression('#^700 ' . $root . '$#', $lines[3]);

        // The staging tree is gone afterwards.
        self::assertDirectoryDoesNotExist(substr($lines[3], 4));

        // Inside the archive: dump and manifest are owner-only; project files keep their own modes (restore BC).
        $list = new Process(['tar', '-tvzf', $artifact->getAbsolutePath()]);
        $list->mustRun();
        $modes = [];
        foreach (explode("\n", trim($list->getOutput())) as $row) {
            $cols                       = preg_split('/\s+/', $row) ?: [];
            $modes[(string) end($cols)] = (string) ($cols[0] ?? '');
        }
        self::assertSame('-rw-------', $modes['./database/dump.sql'] ?? null);
        self::assertSame('-rw-------', $modes['./MANIFEST.json'] ?? null);
        $sourceMode = (int) fileperms($this->projectDir . '/config/app.yaml') & 0o777;
        self::assertSame(sprintf('%o', $sourceMode), $this->octal($modes['./config/app.yaml'] ?? ''));
    }

    /** "-rw-r--r--" → "644". */
    private function octal(string $symbolic): string
    {
        $bits = 0;
        foreach (str_split(substr($symbolic, 1, 9)) as $i => $char) {
            if ($char !== '-') {
                $bits |= 1 << (8 - $i);
            }
        }

        return sprintf('%o', $bits);
    }
}
