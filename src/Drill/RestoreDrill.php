<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Drill;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\PrivateTempDirectory;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;

use function abs;
use function array_keys;
use function array_unique;
use function count;
use function hrtime;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function preg_match;
use function round;
use function sort;
use function strtolower;
use function sys_get_temp_dir;

/**
 * Restore drill (1.29+, Beacon Q-B8): loads a backup's dump into a throwaway schema, compares row counts and
 * `CHECKSUM TABLE` values with the `table_stats` recorded at backup time, and always drops the schema it created.
 *
 * The schema must not exist (it is created, never reused) and must differ from the live database. EO-M3: dumps that
 * select or create databases are refused, the client runs with `--one-database`, and `backup.drill.database_url`
 * lets the drill connect as an account granted only on the throwaway schema.
 */
final class RestoreDrill
{
    public function __construct(
        private readonly BackupArchiver $archiver,
        private readonly DumpImporter $importer,
        private readonly TableStatsCollector $collector,
        private readonly ?string $workDir = null,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ?DumpImporter $liveImporter = null,
    ) {
    }

    /**
     * @param int $rowTolerance accepted absolute row-count difference per table (checksums are then not compared)
     * @param (callable(): void)|null $tick
     *
     * @throws InvalidArgumentException when the schema name is unsafe or the archive has no table statistics
     */
    public function run(BackupArtifact $artifact, string $schema, #[SensitiveParameter] ?ArchivePassword $password = null, int $rowTolerance = 0, ?callable $tick = null): DrillReport
    {
        $live = ($this->liveImporter ?? $this->importer)->databaseName();
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $schema) !== 1 || strtolower($schema) === strtolower($live)) {
            throw new InvalidArgumentException('The drill schema must match [A-Za-z0-9_]{1,64} and differ from the live database.');
        }
        $expected = self::expected($artifact);

        $started = hrtime(true);
        $work    = PrivateTempDirectory::create('nowo-site-backup-drill-', $this->workDir ?? sys_get_temp_dir());
        $created = false;

        try {
            $this->archiver->extractTo($artifact, $work, $tick, $password);
            $dump = $work . '/database/dump.sql';
            if (!is_file($dump)) {
                throw new InvalidArgumentException('The backup holds no database dump.');
            }

            $this->importer->createSchema($schema);
            $created = true;
            $this->importer->importInto($dump, $schema, $tick);
            $actual = $this->collector->collect($schema);
        } finally {
            if ($created) {
                $this->importer->dropSchema($schema);
            }
            $this->filesystem->remove($work);
        }

        $mismatches = self::compare($expected, $actual, $rowTolerance);

        return new DrillReport($mismatches === [], count($expected), $mismatches, round((hrtime(true) - $started) / 1e9, 3));
    }

    /**
     * @param array<string, array{rows: int, checksum: ?string}> $expected
     * @param array<string, array{rows: int, checksum: ?string}> $actual
     *
     * @return list<array{table: string, expected_rows: ?int, actual_rows: ?int, expected_checksum: ?string, actual_checksum: ?string}>
     */
    public static function compare(array $expected, array $actual, int $rowTolerance = 0): array
    {
        $tables = array_unique([...array_keys($expected), ...array_keys($actual)]);
        sort($tables);

        $mismatches = [];
        foreach ($tables as $table) {
            $e  = $expected[$table] ?? null;
            $a  = $actual[$table] ?? null;
            $ok = $e !== null && $a !== null && abs($e['rows'] - $a['rows']) <= $rowTolerance
                && ($rowTolerance > 0 || $e['checksum'] === null || $e['checksum'] === $a['checksum']);
            if (!$ok) {
                $mismatches[] = [
                    'table'             => (string) $table,
                    'expected_rows'     => $e['rows'] ?? null,
                    'actual_rows'       => $a['rows'] ?? null,
                    'expected_checksum' => $e['checksum'] ?? null,
                    'actual_checksum'   => $a['checksum'] ?? null,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * @return array<string, array{rows: int, checksum: ?string}>
     */
    private static function expected(BackupArtifact $artifact): array
    {
        $raw = $artifact->getMeta()['table_stats'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('This backup has no table statistics: create it with recordTableStats.');
        }

        $stats = [];
        foreach ($raw as $table => $row) {
            if (is_string($table) && is_array($row) && is_int($row['rows'] ?? null)) {
                $stats[$table] = ['rows' => $row['rows'], 'checksum' => is_string($row['checksum'] ?? null) ? $row['checksum'] : null];
            }
        }

        return $stats;
    }
}
