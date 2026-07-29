<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Step;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\Step\AbstractSetupStep;
use Nowo\SiteBackupBundle\Setup\Step\BootstrapModeStep;
use Nowo\SiteBackupBundle\Setup\Step\ConditionalAnswerStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

final class BootstrapAndConditionalStepsTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-bootstrap-' . uniqid('', true);
        $this->fs->mkdir($this->dir . '/var/site-backup');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testBootstrapModeWaitsWithoutChoice(): void
    {
        $step   = new BootstrapModeStep('bootstrap_mode_0', 'Choose');
        $ctx    = new SetupContext($this->dir, 'fresh_install');
        $result = $step->run($ctx, new SetupStepInput());
        self::assertTrue($result->isWaitingForInput());
    }

    public function testBootstrapGuidedSetsAnswer(): void
    {
        $step   = new BootstrapModeStep('bootstrap_mode_0', 'Choose');
        $ctx    = new SetupContext($this->dir, 'fresh_install');
        $result = $step->run($ctx, new SetupStepInput(['bootstrap_mode' => 'guided']));
        self::assertTrue($result->isSuccess());
        self::assertSame('guided', $ctx->getAnswer('bootstrap_mode'));

        $again = $step->run($ctx, new SetupStepInput());
        self::assertTrue($again->isSuccess());
        self::assertStringContainsString('guided', $again->getMessage());
    }

    public function testBootstrapAcceptsAbsoluteSqlPath(): void
    {
        $dump = $this->dir . '/dump.sql';
        $this->fs->dumpFile($dump, 'SELECT 1;');
        $step = new BootstrapModeStep('bootstrap_mode_0', 'Choose');
        $ctx  = new SetupContext($this->dir, 'fresh_install');
        $ok   = $step->run($ctx, new SetupStepInput([
            'bootstrap_mode'  => 'full_database',
            'sql_import_path' => $dump,
        ]));
        self::assertTrue($ok->isSuccess());
        self::assertSame($dump, $ctx->getAnswer('sql_import_path'));
    }

    public function testBootstrapFullDatabaseRequiresExistingDump(): void
    {
        $step    = new BootstrapModeStep('bootstrap_mode_0', 'Choose');
        $ctx     = new SetupContext($this->dir, 'fresh_install');
        $waiting = $step->run($ctx, new SetupStepInput(['bootstrap_mode' => 'full_database']));
        self::assertTrue($waiting->isWaitingForInput());

        $dump = $this->dir . '/var/site-backup/full-import.sql';
        $this->fs->dumpFile($dump, 'SELECT 1;');
        $ok = $step->run($ctx, new SetupStepInput([
            'bootstrap_mode'  => 'full_database',
            'sql_import_path' => 'var/site-backup/full-import.sql',
        ]));
        self::assertTrue($ok->isSuccess());
        self::assertSame('full_database', $ctx->getAnswer('bootstrap_mode'));
        self::assertSame('var/site-backup/full-import.sql', $ctx->getAnswer('sql_import_path'));
    }

    public function testBootstrapFullDatabaseUsesDefaultPathWhenPresent(): void
    {
        $this->fs->dumpFile($this->dir . '/var/site-backup/last-restore-dump.sql', 'SELECT 1;');
        $step = new BootstrapModeStep('bootstrap_mode_0', 'Choose');
        $ctx  = new SetupContext($this->dir, 'fresh_install');
        $ok   = $step->run($ctx, new SetupStepInput(['bootstrap_mode' => 'full_database']));
        self::assertTrue($ok->isSuccess());
        self::assertSame('var/site-backup/last-restore-dump.sql', $ctx->getAnswer('sql_import_path'));
    }

    public function testConditionalAnswerStepGatesInner(): void
    {
        $inner = new class('migrations_0', 'Run migrations') extends AbstractSetupStep {
            public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
            {
                return SetupStepResult::ok('ran');
            }
        };
        $step = new ConditionalAnswerStep($inner, 'bootstrap_mode', 'full_database');
        $ctx  = new SetupContext($this->dir, 'fresh_install');
        self::assertFalse($step->isEnabled($ctx));
        $ctx->setAnswer('bootstrap_mode', 'full_database');
        self::assertTrue($step->isEnabled($ctx));
        self::assertSame('migrations_0', $step->getId());
        self::assertSame('Run migrations', $step->getLabel());
        self::assertSame('auto', $step->getUiKind());
        self::assertFalse($step->isComplete($ctx));
        self::assertTrue($step->run($ctx, new SetupStepInput())->isSuccess());
    }

    public function testFactoryCreatesBootstrapAndWhenAnswer(): void
    {
        $markers = new SetupMarkerManager(
            $this->dir . '/req',
            $this->dir . '/done',
        );
        $factory = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );
        $bootstrap = $factory->create(['type' => 'bootstrap_mode'], 0);
        self::assertSame('bootstrap_mode_0', $bootstrap->getId());

        $sql = $factory->create([
            'type'        => 'sql_file',
            'paths'       => ['var/site-backup/full-import.sql'],
            'when_answer' => ['bootstrap_mode' => 'full_database'],
        ], 1);
        $ctx = new SetupContext($this->dir, 'fresh_install');
        self::assertFalse($sql->isEnabled($ctx));
        $ctx->setAnswer('bootstrap_mode', 'full_database');
        self::assertTrue($sql->isEnabled($ctx));

        $this->fs->dumpFile($this->dir . '/var/site-backup/full-import.sql', 'SELECT 1;');
        $conn = new class {
            public function executeStatement(string $sql): int
            {
                return 1;
            }
        };
        $factoryWithDb = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
            $conn,
        );
        $import = $factoryWithDb->create([
            'type'  => 'sql_file',
            'paths' => ['var/site-backup/missing.sql'],
        ], 3);
        $ctxImport = new SetupContext($this->dir, 'fresh_install');
        $ctxImport->setAnswer('sql_import_path', 'var/site-backup/full-import.sql');
        self::assertTrue($import->run($ctxImport, new SetupStepInput())->isSuccess());

        $custom = new class('custom_0', 'Custom') extends AbstractSetupStep {
            public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
            {
                return SetupStepResult::ok('ok');
            }
        };
        $factory2 = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
            null,
            ['my_custom' => $custom],
        );
        $wrapped = $factory2->create([
            'type'        => 'my_custom',
            'when_answer' => ['bootstrap_mode' => 'guided'],
        ], 2);
        $ctx2 = new SetupContext($this->dir, 'fresh_install');
        self::assertFalse($wrapped->isEnabled($ctx2));
        $ctx2->setAnswer('bootstrap_mode', 'guided');
        self::assertTrue($wrapped->isEnabled($ctx2));
    }
}
