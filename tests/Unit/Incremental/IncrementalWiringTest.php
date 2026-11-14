<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCaptureInterface;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisites;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisitesInterface;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\BinlogServerInterface;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class IncrementalWiringTest extends TestCase
{
    public function testDefaultsLeaveIncrementalsOffForTheCommandDumper(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame('mysqlbinlog', $config['backup']['database']['mysqlbinlog_binary']);
        self::assertSame(604800, $config['backup']['database']['binlog_min_expiry_seconds']);

        $container = $this->load([]);
        $archiver  = $container->getDefinition(BackupArchiver::class);
        self::assertNull($archiver->getArgument('$binlogCapture'));
        self::assertNull($archiver->getArgument('$binlogServer'));
        // The services exist (commands, hosts), they only act when a chain backup or restore asks.
        self::assertSame(BinlogCapture::class, (string) $container->getAlias(BinlogCaptureInterface::class));
        self::assertSame(BinlogPrerequisites::class, (string) $container->getAlias(BinlogPrerequisitesInterface::class));
        self::assertSame(MysqlClientBinlogServer::class, (string) $container->getAlias(BinlogServerInterface::class));
        self::assertSame(SiteBackupManager::class, (string) $container->getAlias(IncrementalBackupManagerInterface::class));
        self::assertSame(ChainRestorer::class, (string) $container->getDefinition(SiteBackupManager::class)->getArgument('$chainRestorer'));
        self::assertSame('nowo_site_backup.chain.importer', (string) $container->getDefinition(ChainRestorer::class)->getArgument('$importer'));
        // The chain restore holds the operation lock: its importer must not try to take it again.
        self::assertArrayNotHasKey('$operationLock', $container->getDefinition('nowo_site_backup.chain.importer')->getArguments());
    }

    public function testBuiltinDumperWiresTheBinlogServices(): void
    {
        $container = $this->load(['backup' => ['database' => ['dumper' => 'builtin', 'mysqlbinlog_binary' => '/opt/mysql/bin/mysqlbinlog', 'binlog_min_expiry_seconds' => 86400]]]);
        $archiver  = $container->getDefinition(BackupArchiver::class);

        self::assertSame(BinlogCaptureInterface::class, (string) $archiver->getArgument('$binlogCapture'));
        self::assertSame(BinlogServerInterface::class, (string) $archiver->getArgument('$binlogServer'));
        self::assertSame('/opt/mysql/bin/mysqlbinlog', $container->getDefinition(MysqlBinlogTool::class)->getArgument('$binary'));
        self::assertSame(86400, $container->getDefinition(BinlogPrerequisites::class)->getArgument('$minExpirySeconds'));
        self::assertSame('/opt/mysql/bin/mysqlbinlog', $container->getDefinition(BinlogPrerequisites::class)->getArgument('$binaries')['mysqlbinlog']);

        // PP-M4 / PP-L6 / PP-M3: dedicated accounts, TLS options and the capture mode.
        $container = $this->load(['backup' => ['database' => [
            'dumper'         => 'builtin',
            'url'            => 'mysql://app@db/app',
            'binlog_url'     => 'mysql://bk@db/app',
            'restore_url'    => 'mysql://rs@db/app',
            'binlog_capture' => 'raw',
            'extra_options'  => ['--ssl-mode=VERIFY_IDENTITY', '--column-statistics=0'],
        ]]]);
        self::assertSame('mysql://bk@db/app', $container->getDefinition(MysqlClientBinlogServer::class)->getArgument('$databaseUrl'));
        self::assertSame(['--ssl-mode=VERIFY_IDENTITY'], $container->getDefinition(MysqlClientBinlogServer::class)->getArgument('$tlsOptions'));
        self::assertSame('raw', $container->getDefinition(BinlogCapture::class)->getArgument('$mode'));
        self::assertSame('mysql://rs@db/app', $container->getDefinition(BinlogReplayer::class)->getArgument('$databaseUrl'));
        self::assertSame('mysql://rs@db/app', $container->getDefinition('nowo_site_backup.chain.importer')->getArgument('$databaseUrl'));
        self::assertSame('mysql://bk@db/app', $container->getDefinition(BuiltinMysqlDumper::class)->getArgument('$binlogDatabaseUrl'));
        self::assertSame('filtered', (new Processor())->processConfiguration(new Configuration(), [[]])['backup']['database']['binlog_capture']);
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
