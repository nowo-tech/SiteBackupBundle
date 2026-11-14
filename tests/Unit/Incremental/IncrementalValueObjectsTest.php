<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\CapturedBinlog;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\Check;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Incremental\MysqlToolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_fill;
use function hash;
use function implode;
use function str_repeat;

final class IncrementalValueObjectsTest extends TestCase
{
    public const UUID = '3e11fa47-71ca-11e1-9e33-c80aa9429562';

    public function testCoordinatesNormaliseAndExpose(): void
    {
        $c = new BinlogCoordinates('binlog.000042', 157, strtoupper(self::UUID), "uuid-a:1-5,\n  uuid-b:1-3");

        self::assertSame(42, $c->sequence());
        self::assertSame('binlog', $c->basename());
        self::assertSame(self::UUID, $c->serverUuid);
        self::assertSame('uuid-a:1-5,uuid-b:1-3', $c->gtidExecuted);
        self::assertSame(['binlog_file' => 'binlog.000042', 'binlog_position' => 157, 'server_uuid' => self::UUID, 'gtid_executed' => 'uuid-a:1-5,uuid-b:1-3'], $c->toArray());
        self::assertTrue($c->equals(BinlogCoordinates::fromArray($c->toArray()) ?? self::fail('round trip')));
        self::assertFalse($c->equals(new BinlogCoordinates('binlog.000042', 158, self::UUID)));
        self::assertSame('mysql-bin.log', BinlogCoordinates::basenameOf('mysql-bin.log.000001'));
    }

    /**
     * @return iterable<string, array{string, int, string, ?string}>
     */
    public static function invalidCoordinates(): iterable
    {
        yield 'no sequence' => ['binlog', 4, self::UUID, null];
        yield 'path' => ['../binlog.000001', 4, self::UUID, null];
        yield 'position' => ['binlog.000001', 3, self::UUID, null];
        yield 'uuid' => ['binlog.000001', 4, 'not-a-uuid', null];
        yield 'gtid' => ['binlog.000001', 4, self::UUID, "x'; DROP"];
        yield 'gtid too long' => ['binlog.000001', 4, self::UUID, str_repeat('a', 65536)];
    }

    #[DataProvider('invalidCoordinates')]
    public function testCoordinatesRefuseBadValues(string $file, int $position, string $uuid, ?string $gtid): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BinlogCoordinates($file, $position, $uuid, $gtid);
    }

    public function testCoordinatesFromArrayIsNullForMissingOrBrokenMeta(): void
    {
        self::assertNull(BinlogCoordinates::fromArray([]));
        self::assertNull(BinlogCoordinates::fromArray(['binlog_file' => 'binlog.000001', 'binlog_position' => '4', 'server_uuid' => self::UUID]));
        self::assertNull(BinlogCoordinates::fromArray(['binlog_file' => 'binlog.000001', 'binlog_position' => 4, 'server_uuid' => self::UUID, 'gtid_executed' => 5]));
        self::assertNull(BinlogCoordinates::fromArray(['binlog_file' => 'binlog', 'binlog_position' => 4, 'server_uuid' => self::UUID]));
        self::assertNotNull(BinlogCoordinates::fromArray(['binlog_file' => 'binlog.000001', 'binlog_position' => 4, 'server_uuid' => self::UUID, 'gtid_executed' => null]));
        $this->expectException(InvalidArgumentException::class);
        BinlogCoordinates::sequenceOf('nope');
    }

    public function testCapturedBinlogAndArtifact(): void
    {
        $sha  = hash('sha256', 'x');
        $from = new BinlogCoordinates('binlog.000002', 100, self::UUID);
        $to   = new BinlogCoordinates('binlog.000003', 500, self::UUID);
        $a    = new IncrementalArtifact($from, $to, 'app', [new CapturedBinlog('binlog.000002', '/x/2', 10, $sha), new CapturedBinlog('binlog.000003', '/x/3', 500, $sha)], '/x');

        self::assertSame(['binlog.000002', 'binlog.000003'], $a->fileNames());
        self::assertFalse($a->isEmpty());
        self::assertTrue((new IncrementalArtifact($from, $from, 'app', [], '/x'))->isEmpty());

        try {
            new IncrementalArtifact($from, $to, 'app', [new CapturedBinlog('binlog.000002', '/x/2', 10, $sha), new CapturedBinlog('binlog.000004', '/x/4', 1, $sha)], '/x');
            self::fail('gap accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            new IncrementalArtifact($from, new BinlogCoordinates('binlog.000003', 4, 'aaaaaaaa-71ca-11e1-9e33-c80aa9429562'), 'app', [], '/x');
            self::fail('two servers accepted');
        } catch (InvalidArgumentException) {
        }
        foreach ([['bin', 1, $sha], ['binlog.000001', -1, $sha], ['binlog.000001', 1, 'nope']] as [$name, $size, $hash]) {
            try {
                new CapturedBinlog($name, '/x', $size, $hash);
                self::fail('bad captured binlog accepted');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testChecks(): void
    {
        self::assertTrue(Check::allPass([Check::ok('a', 'x'), Check::warning('b', 'y')]));
        self::assertFalse(Check::allPass([Check::ok('a', 'x'), Check::fail('c', 'z')]));
        self::assertTrue(Check::fail('c', 'z')->isFailure());
        $this->expectException(InvalidArgumentException::class);
        new Check('a', 'maybe', 'x');
    }

    public function testManifestValidationSortingAndHash(): void
    {
        $sha   = hash('sha256', 'a');
        $scope = FileManifest::scopeOf(['config'], ['*.log']);
        $a     = new FileManifest(['b.txt' => [1, 10, $sha], 'a.txt' => [2, -1, $sha]], $scope);
        $b     = FileManifest::fromArray(['scope' => $scope, 'entries' => ['a.txt' => ['size' => 2, 'mtime' => -1, 'sha256' => $sha], 'b.txt' => [1, 10, $sha]]]);

        self::assertSame(['a.txt', 'b.txt'], array_keys($a->entries));
        self::assertSame($a->sha256(), $b->sha256());
        self::assertSame(2, $a->count());
        self::assertSame([2, -1, $sha], $a->get('a.txt'));
        self::assertNull($a->get('c.txt'));
        self::assertSame(['b.txt'], $a->deletedIn(new FileManifest(['a.txt' => [2, 3, $sha]], $scope)));
        self::assertNotSame($scope, FileManifest::scopeOf(['config', 'public'], ['*.log']));

        // A path of digits only comes back from JSON as an integer key.
        $numeric = FileManifest::fromArray(['scope' => $scope, 'entries' => [0 => [1, 1, $sha]]]);
        self::assertSame([1, 1, $sha], $numeric->get('0'));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidManifests(): iterable
    {
        $sha   = hash('sha256', 'a');
        $scope = hash('sha256', 's');
        yield 'scope' => [['scope' => 'x', 'entries' => []]];
        yield 'no entries' => [['scope' => $scope]];
        yield 'entry not array' => [['scope' => $scope, 'entries' => ['a' => 'x']]];
        yield 'negative size' => [['scope' => $scope, 'entries' => ['a' => [-1, 1, $sha]]]];
        yield 'mtime' => [['scope' => $scope, 'entries' => ['a' => [1, -2, $sha]]]];
        yield 'sha' => [['scope' => $scope, 'entries' => ['a' => [1, 1, 'nope']]]];
        yield 'dotdot' => [['scope' => $scope, 'entries' => ['a/../b' => [1, 1, $sha]]]];
        yield 'absolute' => [['scope' => $scope, 'entries' => ['/etc/passwd' => [1, 1, $sha]]]];
        yield 'dot' => [['scope' => $scope, 'entries' => ['./a' => [1, 1, $sha]]]];
        yield 'control' => [['scope' => $scope, 'entries' => ["a\nb" => [1, 1, $sha]]]];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidManifests')]
    public function testManifestRefusesBadEntries(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        FileManifest::fromArray($data);
    }

    public function testToolVersions(): void
    {
        self::assertSame('9.7.2', MysqlToolVersion::oracleClient('mysqlbinlog  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)'));
        self::assertSame('8.0.36', MysqlToolVersion::oracleClient('mysql  Ver 14.14 Distrib 8.0.36, for Linux'));
        self::assertNull(MysqlToolVersion::oracleClient('mysqldump from 11.8.2-MariaDB, client 10.19'));
        self::assertNull(MysqlToolVersion::oracleClient('garbage'));
        self::assertSame('8.0.36', MysqlToolVersion::oracleServer('8.0.36-log'));
        self::assertNull(MysqlToolVersion::oracleServer('11.4.2-MariaDB-log'));
        self::assertNull(MysqlToolVersion::oracleServer('x'));
        self::assertTrue(MysqlToolVersion::supports('8.0.26'));
        self::assertFalse(MysqlToolVersion::supports('8.0.25'));
        self::assertFalse(MysqlToolVersion::supports(null));
    }

    public function testReportStepsAndExceptions(): void
    {
        $steps = [
            new ChainRestoreStep('a', ChainRestoreStep::PART_DATABASE_DUMP, ChainRestoreStep::APPLIED, 'ok'),
            new ChainRestoreStep('b', ChainRestoreStep::PART_BINLOG, ChainRestoreStep::FAILED),
            new ChainRestoreStep('a', ChainRestoreStep::PART_FILES, ChainRestoreStep::NOT_APPLIED),
        ];
        $report = new ChainRestoreReport(['a', 'b'], $steps, false, new DateTimeImmutable('2026-01-01'), 'boom');
        self::assertCount(1, $report->stepsWith(ChainRestoreStep::NOT_APPLIED));
        self::assertSame('kept', $steps[0]->with(ChainRestoreStep::APPLIED, 'kept')->detail);
        self::assertSame('ok', $steps[0]->with(ChainRestoreStep::SKIPPED)->detail);

        $e = new ChainRestoreException($report, new RuntimeException('inner'));
        self::assertSame($report, $e->getReport());
        self::assertStringContainsString('Applied: a database_dump', $e->getMessage());
        self::assertStringContainsString('Failed (may be partly applied): b binlog', $e->getMessage());
        self::assertStringContainsString('Not applied: a files', $e->getMessage());
        self::assertStringContainsString('none', (new ChainRestoreException(new ChainRestoreReport([], [], false)))->getMessage());

        $broken = new ChainBrokenException(array_fill(0, 12, 'p'));
        self::assertCount(12, $broken->getProblems());
        self::assertStringContainsString('(+2 more)', $broken->getMessage());
        self::assertStringContainsString(implode(' ', ['p', 'p']), $broken->getMessage());

        self::assertSame(BinlogGapException::EXPIRED, (new BinlogGapException(BinlogGapException::EXPIRED, 'x'))->getReason());

        $this->expectException(InvalidArgumentException::class);
        new ChainRestoreStep('a', 'other', ChainRestoreStep::APPLIED);
    }
}
