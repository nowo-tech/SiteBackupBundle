<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Crypto\ArchiveEncryptorInterface;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\MysqlClientSchemaInspector;
use Nowo\SiteBackupBundle\Database\SchemaInspectorInterface;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Drill\RestoreDrill;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Remote\BackupRemoteTargetInterface;
use Nowo\SiteBackupBundle\Remote\FlysystemRemoteTarget;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

final class EngineWiringTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        $backup = $config['backup'];

        self::assertNotContains('.env', $backup['include_paths']);
        self::assertSame([], $backup['forbidden_paths']);
        self::assertSame('%kernel.project_dir%/var/site-backup/work', $backup['work_dir']);
        self::assertSame('tar.gz', $backup['default_format']);
        self::assertSame(6, $backup['compression_level']);
        self::assertTrue($backup['preflight_disk_check']);
        self::assertSame(['keep_last' => null, 'max_age_days' => null], $backup['retention']);
        self::assertSame('nowo_site_backup.operation', $backup['lock']['key']);
        self::assertSame('command', $backup['database']['dumper']);
        self::assertSame('%env(default::DATABASE_URL)%', $backup['database']['url']);
    }

    public function testCommandDumperWiring(): void
    {
        $container = $this->load([]);

        $archiver = $container->getDefinition(BackupArchiver::class);
        self::assertNull($archiver->getArgument('$builtinDumper'));
        self::assertSame('/tmp/sbb-wiring/var/site-backup/work', $container->getParameterBag()->resolveValue($archiver->getArgument('$workDir')));
        self::assertTrue($archiver->getArgument('$preflightDiskCheck'));
        self::assertSame(SecretPathPolicy::class, (string) $archiver->getArgument('$secretPolicy'));
        self::assertFalse($container->hasDefinition(BuiltinMysqlDumper::class));
        self::assertTrue($container->hasDefinition(DumpImporter::class));
        self::assertSame(MysqlClientSchemaInspector::class, (string) $container->getAlias(SchemaInspectorInterface::class));

        $manager = $container->getDefinition(SiteBackupManager::class);
        self::assertNull($manager->getArgument('$localRetention'));
        self::assertSame(ArchiveFormat::TarGz, $manager->getArgument('$defaultFormat'));
        self::assertSame(BackupOperationLock::class, (string) $manager->getArgument('$operationLock'));

        $lock = $container->getDefinition(BackupOperationLock::class);
        self::assertSame(2700.0, $lock->getArgument('$ttlSeconds'));
        self::assertInstanceOf(Reference::class, $lock->getArgument('$lockFactory'));

        self::assertSame(DumpImporter::class, (string) $container->getDefinition(SetupStepFactory::class)->getArgument('$dumpImporter'));
    }

    public function testBuiltinDumperRetentionAndForbiddenPaths(): void
    {
        $container = $this->load(['backup' => [
            'forbidden_paths'   => ['var/storage/private'],
            'default_format'    => 'zip',
            'compression_level' => 9,
            'retention'         => ['keep_last' => 7],
            'database'          => [
                'dumper'                      => 'builtin',
                'url'                         => 'mysql://u@h/d',
                'skip_ssl_verify_server_cert' => true,
                'extra_options'               => ['--column-statistics=0'],
                'required_tables'             => ['user'],
            ],
        ]]);

        self::assertSame(['var/storage/private'], $container->getDefinition(SecretPathPolicy::class)->getArgument('$forbiddenPaths'));
        $dumper = $container->getDefinition(BuiltinMysqlDumper::class);
        self::assertSame('mysql://u@h/d', $dumper->getArgument('$databaseUrl'));
        self::assertTrue($dumper->getArgument('$skipSslVerifyServerCert'));
        self::assertSame(['user'], $dumper->getArgument('$requiredTables'));
        self::assertSame(BuiltinMysqlDumper::class, (string) $container->getDefinition(BackupArchiver::class)->getArgument('$builtinDumper'));

        $manager = $container->getDefinition(SiteBackupManager::class);
        $policy  = $manager->getArgument('$localRetention');
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame(RetentionPolicy::class, $policy->getClass());
        self::assertSame(7, $policy->getArgument('$keepLast'));
        self::assertSame(ArchiveFormat::Zip, $manager->getArgument('$defaultFormat'));
        self::assertSame(9, $manager->getArgument('$defaultCompressionLevel'));
    }

    public function testEncryptionSigningDrillAndFlysystemWiring(): void
    {
        $container = $this->load(['backup' => [
            'encryption' => ['age' => ['binary' => '/opt/age/age', 'work_factor' => 16]],
            'signing'    => ['secret_key' => '%env(default::SITE_BACKUP_SIGNING_KEY)%', 'key_id' => 'prod', 'public_keys' => ['old' => 'AAAA']],
            'remote'     => ['flysystem_storage' => 'backups.storage'],
        ]]);

        $age = $container->getDefinition(AgeBinaryEncryptor::class);
        self::assertSame('/opt/age/age', $age->getArgument('$binary'));
        self::assertSame(16, $age->getArgument('$workFactor'));
        self::assertSame(20, $age->getArgument('$maxWorkFactor'));
        self::assertSame(AgeBinaryEncryptor::class, (string) $container->getAlias(ArchiveEncryptorInterface::class));
        self::assertSame('prod', $container->getDefinition(BackupSigner::class)->getArgument('$keyId'));
        self::assertSame(['old' => 'AAAA'], $container->getDefinition(BackupSigner::class)->getArgument('$publicKeys'));
        self::assertTrue($container->hasDefinition(RestoreDrill::class));
        self::assertTrue($container->hasDefinition(TableStatsCollector::class));
        self::assertSame('backups.storage', (string) $container->getDefinition(FlysystemRemoteTarget::class)->getArgument('$filesystem'));
        self::assertSame(FlysystemRemoteTarget::class, (string) $container->getAlias(BackupRemoteTargetInterface::class));
        self::assertSame(ArchiveEncryptorInterface::class, (string) $container->getDefinition(BackupArchiver::class)->getArgument('$encryptor'));

        self::assertFalse($this->load([])->hasDefinition(FlysystemRemoteTarget::class));
    }

    public function testDrillDatabaseUrlWiresDedicatedClients(): void
    {
        $container = $this->load(['backup' => ['drill' => ['database_url' => 'mysql://drill:CANARY-drill-pass-0000@db/beacon_drill']]]);

        self::assertSame('mysql://drill:CANARY-drill-pass-0000@db/beacon_drill', $container->getDefinition('nowo_site_backup.drill.importer')->getArgument('$databaseUrl'));
        self::assertTrue($container->hasDefinition('nowo_site_backup.drill.stats'));
        $drill = $container->getDefinition(RestoreDrill::class);
        self::assertSame('nowo_site_backup.drill.importer', (string) $drill->getArgument('$importer'));
        self::assertSame('nowo_site_backup.drill.stats', (string) $drill->getArgument('$collector'));
        self::assertSame(DumpImporter::class, (string) $drill->getArgument('$liveImporter'));
    }

    public function testUnsafeExtraOptionsFailAtBuild(): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        $this->load(['backup' => ['database' => ['dumper' => 'builtin', 'extra_options' => ['--result-file=/tmp/x']]]]);
    }

    public function testSecretIncludePathIsDeprecated(): void
    {
        $messages = [];
        // @phpstan-ignore-next-line (test-only, restored in finally)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->load(['backup' => ['include_paths' => ['config', '.env', 'var/secrets']]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(2, $messages);
        self::assertStringContainsString('".env"', $messages[0]);
    }

    public function testHostSchemaInspectorAliasIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/tmp/sbb-wiring');
        $container->setAlias(SchemaInspectorInterface::class, 'app.inspector');
        (new SiteBackupExtension())->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertSame('app.inspector', (string) $container->getAlias(SchemaInspectorInterface::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/tmp/sbb-wiring');
        (new SiteBackupExtension())->load([['security' => ['allow_unauthenticated' => true]] + $config], $container);

        return $container;
    }
}
