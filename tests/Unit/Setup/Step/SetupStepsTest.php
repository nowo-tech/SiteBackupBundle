<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Step;

use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Setup\Step\CacheClearStep;
use Nowo\SiteBackupBundle\Setup\Step\ConsoleStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseCreateStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\MarkerStep;
use Nowo\SiteBackupBundle\Setup\Step\MigrationsStep;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Step\SampleDataStep;
use Nowo\SiteBackupBundle\Setup\Step\SchemaUpdateStep;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

final class SetupStepsTest extends TestCase
{
    private string $dir;
    private SetupContext $ctx;
    private ConsoleProcessRunner $runner;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-steps-' . uniqid('', true);
        $this->fs->mkdir($this->dir . '/var');
        $this->ctx    = new SetupContext($this->dir, 'fresh_install');
        $this->runner = new ConsoleProcessRunner($this->dir, PHP_BINARY, 30);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testRequirementsStep(): void
    {
        $step   = new RequirementsStep('req', 'Requirements', ['json'], ['var'], true);
        $result = $step->run($this->ctx, new SetupStepInput());
        self::assertTrue($result->isSuccess());
    }

    public function testDatabaseUrlStep(): void
    {
        $step = new DatabaseUrlStep('db', 'DB', optional: true);
        self::assertTrue($step->run($this->ctx, new SetupStepInput())->isSuccess());

        $required = new DatabaseUrlStep('db2', 'DB', optional: false);
        self::assertTrue($required->run($this->ctx, new SetupStepInput())->isWaitingForInput());
        self::assertTrue($required->run($this->ctx, new SetupStepInput(['action' => 'skip']))->isSuccess());

        $fail = new DatabaseUrlStep('db3', 'DB', optional: false);
        self::assertFalse($fail->run($this->ctx, new SetupStepInput(['database_url' => 'not-a-url']))->isSuccess());

        $ok = new DatabaseUrlStep('db4', 'DB', optional: false);
        self::assertTrue($ok->run($this->ctx, new SetupStepInput(['database_url' => 'mysql://user:pass@localhost/db']))->isSuccess());
        self::assertFileExists($this->dir . '/.env.local');

        file_put_contents($this->dir . '/.env.local', "DATABASE_URL=old\nOTHER=1\n");
        $quoted = new DatabaseUrlStep('db5', 'DB');
        self::assertTrue($quoted->run($this->ctx, new SetupStepInput(['database_url' => 'mysql://user:pass with space@localhost/db']))->isSuccess());
        $envLocal = file_get_contents($this->dir . '/.env.local');
        self::assertIsString($envLocal);
        self::assertStringContainsString('DATABASE_URL=', $envLocal);
    }

    public function testAdminUserStep(): void
    {
        $provisioner = new class implements AdminUserProvisionerInterface {
            public bool $exists = false;

            public function adminExists(): bool
            {
                return $this->exists;
            }

            public function createAdmin(array $data): void
            {
                $this->exists = true;
            }
        };

        $skip                = new AdminUserStep('admin', 'Admin', $provisioner, skipIfAdminExists: true);
        $provisioner->exists = true;
        self::assertTrue($skip->isComplete($this->ctx));
        self::assertTrue($skip->run($this->ctx, new SetupStepInput())->isSuccess());

        $waiting = new AdminUserStep('admin2', 'Admin', $provisioner, skipIfAdminExists: false);
        self::assertTrue($waiting->run($this->ctx, new SetupStepInput())->isWaitingForInput());

        $fail = new AdminUserStep('admin3', 'Admin', new NullAdminUserProvisioner(), skipIfAdminExists: false);
        self::assertFalse($fail->run($this->ctx, new SetupStepInput(['email' => 'a@b.c', 'password' => 'x']))->isSuccess());

        $ok = new AdminUserStep('admin4', 'Admin', $provisioner, skipIfAdminExists: false);
        self::assertTrue($ok->run($this->ctx, new SetupStepInput(['email' => 'a@b.c', 'password' => 'secret']))->isSuccess());
    }

    public function testSampleDataStep(): void
    {
        $disabled = new SampleDataStep('sample', 'Sample', $this->runner, []);
        self::assertFalse($disabled->isEnabled($this->ctx));

        $this->fs->mkdir($this->dir . '/bin');
        file_put_contents($this->dir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");

        $step = new SampleDataStep('sample2', 'Sample', $this->runner, [['list']], 'opt_in');
        self::assertTrue($step->run($this->ctx, new SetupStepInput(['action' => 'skip']))->isSuccess());
        self::assertTrue($step->run($this->ctx, new SetupStepInput())->isWaitingForInput());
        self::assertTrue($step->run($this->ctx, new SetupStepInput(['action' => 'load']))->isSuccess());
    }

    public function testSqlFileStep(): void
    {
        $sqlDir = $this->dir . '/sql';
        (new Filesystem())->mkdir($sqlDir);
        file_put_contents($sqlDir . '/init.sql', 'SELECT 1;');
        file_put_contents($sqlDir . '/empty.sql', '');

        $missing = new SqlFileStep('sql', 'SQL', ['/missing.sql']);
        self::assertFalse($missing->run($this->ctx, new SetupStepInput())->isSuccess());

        $ifExists = new SqlFileStep('sql2', 'SQL', ['/missing.sql'], ifExists: true);
        self::assertFalse($ifExists->isEnabled($this->ctx));
        self::assertTrue($ifExists->run($this->ctx, new SetupStepInput())->isSuccess());

        $noConn = new SqlFileStep('sql3', 'SQL', [$sqlDir . '/init.sql']);
        self::assertFalse($noConn->run($this->ctx, new SetupStepInput())->isSuccess());

        $conn = new class {
            /** @var list<string> */
            public array $executed = [];

            public function executeStatement(string $sql): void
            {
                $this->executed[] = $sql;
            }
        };
        $ok = new SqlFileStep('sql4', 'SQL', [$sqlDir], connection: $conn);
        self::assertTrue($ok->run($this->ctx, new SetupStepInput())->isSuccess());
        self::assertNotEmpty($conn->executed);
    }

    public function testMarkerStep(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $step    = new MarkerStep('marker', 'Done', $markers, true);
        self::assertTrue($step->run($this->ctx, new SetupStepInput())->isSuccess());
        self::assertTrue($markers->isDone());

        $noDone = new MarkerStep('marker2', 'Done', $markers, false);
        self::assertTrue($noDone->run($this->ctx, new SetupStepInput())->isSuccess());
    }

    public function testRunnerStepsWithoutBinConsole(): void
    {
        foreach ([
            new DatabaseCreateStep('db', 'DB', $this->runner),
            new CacheClearStep('cache', 'Cache', $this->runner),
            new SchemaUpdateStep('schema', 'Schema', $this->runner),
            new MigrationsStep('mig', 'Migrations', $this->runner),
            new ConsoleStep('cmd', 'Cmd', $this->runner, ['--version']),
        ] as $step) {
            self::assertFalse($step->run($this->ctx, new SetupStepInput())->isSuccess());
        }
    }
}
