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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    public function testDatabaseUrlStepSingleQuotesValueSoDotenvNeverExpandsIt(): void
    {
        $url  = 'mysql://app:pa$HOME$(id)\1$0@db:3306/app?serverVersion=8.0';
        $step = new DatabaseUrlStep('db', 'DB', optional: false);
        self::assertTrue($step->run($this->ctx, new SetupStepInput(['database_url' => $url]))->isSuccess());
        self::assertSame("DATABASE_URL='" . $url . "'\n", file_get_contents($this->dir . '/.env.local'));

        // Existing line replaced literally (no preg backreferences), other lines kept.
        file_put_contents($this->dir . '/.env.local', "APP_ENV=prod\nDATABASE_URL=old\nOTHER=1\n");
        self::assertTrue($step->run($this->ctx, new SetupStepInput(['database_url' => $url]))->isSuccess());
        $env = (string) file_get_contents($this->dir . '/.env.local');
        self::assertSame("APP_ENV=prod\nDATABASE_URL='" . $url . "'\nOTHER=1\n", $env);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDatabaseUrlProvider(): iterable
    {
        yield 'single quote breaks out' => ["mysql://u:p'\$(touch /tmp/pwn)'@db/app"];
        yield 'newline injects a variable' => ["mysql://u:p@db/app\nAPP_SECRET=evil"];
        yield 'carriage return' => ["mysql://u:p@db/app\rX=1"];
        yield 'nul byte' => ["mysql://u:p@db/app\0"];
        yield 'tab control char' => ["mysql://u:p\t@db/app"];
    }

    #[DataProvider('rejectedDatabaseUrlProvider')]
    public function testDatabaseUrlStepRejectsQuotesAndControlCharacters(string $url): void
    {
        file_put_contents($this->dir . '/.env.local', "DATABASE_URL=old\n");
        $step   = new DatabaseUrlStep('db', 'DB', optional: false);
        $result = $step->run($this->ctx, new SetupStepInput(['database_url' => $url]));
        self::assertFalse($result->isSuccess());
        self::assertSame("DATABASE_URL=old\n", file_get_contents($this->dir . '/.env.local'));
        self::assertNull($this->ctx->getAnswer('database_url_set'));
    }

    public function testSqlFileStepRejectsImportPathOutsideSiteBackupDir(): void
    {
        $conn = new class {
            /** @var list<string> */
            public array $executed = [];

            public function executeStatement(string $sql): int
            {
                $this->executed[] = $sql;

                return 1;
            }
        };
        file_put_contents($this->dir . '/secret.sql', 'SELECT 1;');
        $step = new SqlFileStep('sql', 'SQL', [], ifExists: true, connection: $conn);

        foreach (['/etc/passwd', 'secret.sql', 'var/site-backup/../../secret.sql', $this->dir . '/secret.sql'] as $path) {
            $ctx = new SetupContext($this->dir, 'full_database');
            $ctx->setAnswer('sql_import_path', $path);
            self::assertFalse($step->isEnabled($ctx));
            $result = $step->run($ctx, new SetupStepInput());
            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('var/site-backup', $result->getMessage());
        }
        self::assertSame([], $conn->executed);
    }

    public function testSqlFileStepErrorDoesNotEchoDumpContentsAndRestoreDumpIsDeletedAfterImport(): void
    {
        $this->fs->dumpFile($this->dir . '/var/site-backup/last-restore-dump.sql', "INSERT INTO users VALUES ('admin', 'secret-hash');");
        $failing = new class {
            public function executeStatement(string $sql): int
            {
                throw new RuntimeException("An exception occurred while executing a query: SQLSTATE[42000]: Syntax error near '" . $sql . "'");
            }
        };
        $ctx = new SetupContext($this->dir, 'post_restore');
        $ctx->setAnswer('sql_import_path', 'var/site-backup/last-restore-dump.sql');
        $result = (new SqlFileStep('sql', 'SQL', [], connection: $failing))->run($ctx, new SetupStepInput());
        self::assertFalse($result->isSuccess());
        self::assertStringNotContainsString('secret-hash', $result->getMessage());
        self::assertStringContainsString('SQLSTATE[42000]', $result->getMessage());
        self::assertStringContainsString('last-restore-dump.sql', $result->getMessage());
        self::assertFileExists($this->dir . '/var/site-backup/last-restore-dump.sql');

        $noState = new class {
            public function executeStatement(string $sql): int
            {
                throw new RuntimeException('boom ' . $sql);
            }
        };
        $plain = (new SqlFileStep('sql', 'SQL', [], connection: $noState))->run($ctx, new SetupStepInput());
        self::assertStringNotContainsString('secret-hash', $plain->getMessage());

        $ok = new class {
            public function executeStatement(string $sql): int
            {
                return 1;
            }
        };
        $this->fs->dumpFile($this->dir . '/var/site-backup/full-import.sql', 'SELECT 1;');
        $step = new SqlFileStep('sql', 'SQL', ['var/site-backup/full-import.sql'], connection: $ok);
        self::assertTrue($step->run($ctx, new SetupStepInput())->isSuccess());
        self::assertFileDoesNotExist($this->dir . '/var/site-backup/last-restore-dump.sql');
        self::assertFileExists($this->dir . '/var/site-backup/full-import.sql');
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
