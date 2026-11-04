<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use LogicException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function serialize;
use function str_repeat;
use function unserialize;

final class BackupOptionsTest extends TestCase
{
    public function testDefaultsMatchThe127Behaviour(): void
    {
        $options = BackupOptions::fromConfig('nightly', 'cron');

        self::assertTrue($options->includeDatabase);
        self::assertTrue($options->includeFiles);
        self::assertNull($options->includePaths);
        self::assertSame(ArchiveFormat::TarGz, $options->format);
        self::assertSame(6, $options->compressionLevel);
        self::assertSame('nightly', $options->label);
        self::assertSame('cron', $options->createdBy);
        self::assertSame('database_files', $options->content());
    }

    public function testContentAndLoggableArrayCarryNoFreeText(): void
    {
        $options = new BackupOptions(
            includeFiles: false,
            tableRules: ['event_log' => TableRule::StructureOnly, 'cache' => TableRule::Skip],
            format: ArchiveFormat::SqlGz,
            compressionLevel: 9,
            label: 'CANARY-label',
            extraMeta: ['plan' => 'nightly', 'attempt' => 2, 'manual' => false, 'ratio' => 1.5],
        );

        self::assertSame('database', $options->content());
        self::assertSame('files', (new BackupOptions(includeDatabase: false))->content());
        self::assertSame(['event_log' => 'structure', 'cache' => 'skip'], $options->tableRuleValues());
        $loggable = $options->toLoggableArray();
        self::assertSame('sql.gz', $loggable['format']);
        self::assertTrue($loggable['has_label']);
        self::assertStringNotContainsString('CANARY-label', (string) json_encode($loggable));
    }

    public function testWithersReturnNewInstances(): void
    {
        $options = new BackupOptions();
        $beat    = static function (BackupProgress $progress): void {
        };

        $changed = $options->withLabel('a')->withCreatedBy('b')->withFormat(ArchiveFormat::Zip)
            ->withTableRules(['t' => TableRule::Skip])->withHeartbeat($beat);

        self::assertNull($options->label);
        self::assertSame('a', $changed->label);
        self::assertSame('b', $changed->createdBy);
        self::assertSame(ArchiveFormat::Zip, $changed->format);
        self::assertSame(['t' => TableRule::Skip], $changed->tableRules);
        self::assertSame($beat, $changed->heartbeat);
    }

    public function testOptionsCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize(new BackupOptions());
    }

    public function testOptionsCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);
        unserialize('O:41:"Nowo\SiteBackupBundle\Model\BackupOptions":0:{}');
    }

    /**
     * @return iterable<string, array{0: callable(): BackupOptions}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'nothing' => [static fn () => new BackupOptions(includeDatabase: false, includeFiles: false)];
        yield 'sql.gz with files' => [static fn () => new BackupOptions(format: ArchiveFormat::SqlGz)];
        yield 'sql.gz without database' => [static fn () => new BackupOptions(includeDatabase: false, includeFiles: false, format: ArchiveFormat::SqlGz)];
        yield 'level 10' => [static fn () => new BackupOptions(compressionLevel: 10)];
        yield 'level -1' => [static fn () => new BackupOptions(compressionLevel: -1)];
        yield 'sql.gz level 0' => [static fn () => new BackupOptions(includeFiles: false, format: ArchiveFormat::SqlGz, compressionLevel: 0)];
        yield 'rules without database' => [static fn () => new BackupOptions(includeDatabase: false, tableRules: ['t' => TableRule::Skip])];
        yield 'rule name injection' => [static fn () => new BackupOptions(tableRules: ['t --result-file=/x' => TableRule::Skip])];
        yield 'rule name with dot' => [static fn () => new BackupOptions(tableRules: ['db.t' => TableRule::Skip])];
        yield 'numeric rule key' => [static fn () => new BackupOptions(tableRules: [0 => TableRule::Skip])]; // @phpstan-ignore argument.type
        yield 'rule value' => [static fn () => new BackupOptions(tableRules: ['t' => 'skip'])]; // @phpstan-ignore argument.type
        yield 'path escape' => [static fn () => new BackupOptions(includePaths: ['config/../../etc'])];
        yield 'path control char' => [static fn () => new BackupOptions(includePaths: ["config\n"])];
        yield 'path not string' => [static fn () => new BackupOptions(includePaths: [1])]; // @phpstan-ignore argument.type
        yield 'empty label' => [static fn () => new BackupOptions(label: '  ')];
        yield 'long label' => [static fn () => new BackupOptions(label: str_repeat('a', 256))];
        yield 'control label' => [static fn () => new BackupOptions(label: "a\x07")];
        yield 'long actor' => [static fn () => new BackupOptions(createdBy: str_repeat('a', 129))];
        yield 'secret-looking meta key' => [static fn () => new BackupOptions(extraMeta: ['api_token' => 'x'])];
        yield 'bad meta key' => [static fn () => new BackupOptions(extraMeta: ['Plan' => 'x'])];
        yield 'array meta value' => [static fn () => new BackupOptions(extraMeta: ['plan' => ['x']])]; // @phpstan-ignore argument.type
        yield 'long meta value' => [static fn () => new BackupOptions(extraMeta: ['plan' => str_repeat('x', 256)])];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(callable $factory): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        $factory();
    }

    public function testStoreLevelIsAllowedForArchives(): void
    {
        self::assertSame(0, (new BackupOptions(compressionLevel: 0, format: ArchiveFormat::Zip))->compressionLevel);
    }

    public function testFormatHelpers(): void
    {
        self::assertSame(ArchiveFormat::SqlGz, ArchiveFormat::fromFilename('x.sql.gz'));
        self::assertSame(ArchiveFormat::Zip, ArchiveFormat::fromFilename('x.zip'));
        self::assertSame(ArchiveFormat::TarGz, ArchiveFormat::fromFilename('x.tar.gz'));
        self::assertSame(ArchiveFormat::TarGz, ArchiveFormat::fromFilename('legacy'));
        self::assertSame('sql.gz', ArchiveFormat::SqlGz->extension());
        self::assertFalse(ArchiveFormat::SqlGz->hasManifest());
        self::assertTrue(ArchiveFormat::Zip->hasManifest());
    }
}
