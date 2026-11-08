<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Command\SetupResetCommand;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\InitialInstallOnlyStepInterface;
use Nowo\SiteBackupBundle\Setup\InitialInstallOnlySteps;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\Controller\CreatesFormFactory;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function array_map;
use function array_values;
use function is_file;
use function json_decode;

use const PHP_BINARY;

/**
 * Owner decision IN-I1 (2026-11-08): a wizard re-opened on a configured instance shows and runs only the steps that
 * are still missing; admin creation, database_url and the SQL import are refused except during the initial install.
 */
final class SetupReopenSafeStepsTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    private const PROFILES = [
        'fresh_install' => ['steps' => [
            ['type' => 'bootstrap_mode'],
            ['type' => 'database_url', 'optional' => true],
            ['type' => 'sql_file', 'paths' => ['var/site-backup/full-import.sql'], 'when_answer' => ['bootstrap_mode' => 'full_database']],
            ['type' => 'admin_user', 'skip_if_admin_exists' => false],
            ['type' => 'marker', 'write_done' => true],
        ]],
        'admin_only' => ['steps' => [
            ['type' => 'admin_user', 'skip_if_admin_exists' => false],
            ['type' => 'marker', 'write_done' => true],
        ]],
    ];

    private SetupMarkerManager $markers;

    private FilesystemSetupProgressStorage $progress;

    /** @var list<array<string, mixed>> */
    private array $createdAdmins = [];

    /** @var array<string, mixed> */
    private array $lastContext = [];

    protected function setUp(): void
    {
        $this->initHarness();
        $dir            = $this->harnessProjectDir . '/_setup';
        $this->markers  = new SetupMarkerManager($dir . '/required', $dir . '/done');
        $this->progress = new FilesystemSetupProgressStorage($dir . '/progress.json');
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testReopenedMarkerLifecycle(): void
    {
        self::assertSame($this->harnessProjectDir . '/_setup/setup.reopened', $this->markers->getReopenedFile());
        self::assertFalse($this->markers->isReopened());

        $this->markers->markReopened();
        self::assertTrue($this->markers->isReopened());

        $this->markers->markDone();
        self::assertFalse($this->markers->isReopened(), 'completing the wizard ends the reopen');

        $this->markers->clearReopened();
        self::assertFalse($this->markers->isReopened());

        $custom = new SetupMarkerManager($this->harnessProjectDir . '/r', $this->harnessProjectDir . '/d', reopenedFile: $this->harnessProjectDir . '/x/reopened');
        $custom->markReopened();
        self::assertTrue(is_file($this->harnessProjectDir . '/x/reopened'));
    }

    public function testReopenerWritesTheMarkerOnlyForADoneInstance(): void
    {
        $reopener = new SetupWizardReopener($this->markers, $this->progress, new FakeDurableSetupDoneStore(false));
        self::assertFalse($reopener->reopen());
        self::assertFalse($this->markers->isReopened(), 'nothing was done: an initial install stays an initial install');

        $this->markers->markDone();
        self::assertTrue($reopener->reopen());
        self::assertTrue($this->markers->isReopened());
        self::assertFalse($this->markers->isDone());

        $this->markers->clearReopened();
        $durable = new FakeDurableSetupDoneStore(true);
        self::assertTrue((new SetupWizardReopener($this->markers, $this->progress, $durable))->reopen());
        self::assertTrue($this->markers->isReopened(), 'durable store said done');

        $this->markers->clearReopened();
        $broken = new class implements DurableSetupDoneStoreInterface {
            public function isDone(): bool
            {
                throw new RuntimeException('db down');
            }

            public function markDone(): void
            {
            }

            public function clearDone(): void
            {
            }
        };
        self::assertFalse((new SetupWizardReopener($this->markers, $this->progress, $broken))->reopen());
        self::assertFalse($this->markers->isReopened());
    }

    public function testInitialInstallOnlyClassification(): void
    {
        $steps = $this->orchestrator()->getSteps('fresh_install');
        self::assertSame(
            [true, true, true, true, false],
            array_map(static fn (SetupStepInterface $s): bool => InitialInstallOnlySteps::isInitialInstallOnly($s), $steps),
        );

        $host = new class implements InitialInstallOnlyStepInterface {
            public function getId(): string
            {
                return 'host_import';
            }

            public function getLabel(): string
            {
                return 'Host import';
            }

            public function getUiKind(): string
            {
                return 'auto';
            }

            public function isEnabled(SetupContext $ctx): bool
            {
                return true;
            }

            public function isComplete(SetupContext $ctx): bool
            {
                return false;
            }

            public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
            {
                return SetupStepResult::ok('x');
            }
        };
        self::assertTrue(InitialInstallOnlySteps::isInitialInstallOnly($host));
        self::assertSame([], InitialInstallOnlySteps::withoutInitialInstallOnly([$host]));
    }

    public function testInitialInstallStillOffersEveryStep(): void
    {
        $orchestrator = $this->orchestrator();
        self::assertFalse($orchestrator->isReopened());
        self::assertCount(5, $orchestrator->getSteps('fresh_install'));

        $progress = $orchestrator->advance('admin_only', new SetupStepInput(['email' => 'a@b.c', 'password' => 'secret']));
        self::assertSame(SetupProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertCount(1, $this->createdAdmins);
    }

    public function testReopenedOrchestratorLeavesOutAndNeverRunsRefusedSteps(): void
    {
        $this->reopenDoneInstanceThroughTheGate();
        $orchestrator = $this->orchestrator();

        self::assertTrue($orchestrator->isReopened());
        self::assertSame(['marker_4'], array_map(static fn (SetupStepInterface $s): string => $s->getId(), $orchestrator->getSteps('fresh_install')));

        // Same input that creates an admin on an initial install, plus a DATABASE_URL and an SQL path.
        $progress = $orchestrator->advance('fresh_install', new SetupStepInput([
            'email'           => 'evil@example.com',
            'password'        => 'secret',
            'database_url'    => 'mysql://u:p@evil/db',
            'bootstrap_mode'  => 'full_database',
            'sql_import_path' => 'var/site-backup/full-import.sql',
        ]));

        self::assertSame(SetupProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertSame([], $this->createdAdmins);
        self::assertFalse(is_file($this->harnessProjectDir . '/.env.local'));
        self::assertArrayNotHasKey('bootstrap_mode', $progress->getAnswers());
        self::assertTrue($this->markers->isDone());
        self::assertFalse($this->markers->isReopened(), 'completion ends the reopen');
    }

    public function testReopenedWizardPageAndApiRefuseAdminAndDatabaseSteps(): void
    {
        $this->reopenDoneInstanceThroughTheGate();
        $controller = $this->controller('tok');

        // GET render: the steps list has no admin / database_url / SQL import tab.
        $get = $controller->index(Request::create('/_setup?token=tok&profile=fresh_install', 'GET'));
        self::assertSame(200, $get->getStatusCode());
        self::assertTrue($this->lastContext['reopened']);
        self::assertSame(['marker_4'], $this->stepIds($this->lastContext['steps']));

        // POST with admin credentials: the run completes, no admin is created.
        $post = $controller->index(Request::create('/_setup?token=tok', 'POST', [
            'profile'     => 'admin_only',
            '_csrf_token' => 'token',
            'email'       => 'evil@example.com',
            'password'    => 'secret',
        ]));
        self::assertSame(302, $post->getStatusCode());
        self::assertSame([], $this->createdAdmins);
        self::assertTrue($this->markers->isDone());
    }

    public function testReopenedApiRefusesAdminStep(): void
    {
        $this->reopenDoneInstanceThroughTheGate();
        $controller = $this->controller('tok');

        $response = $controller->advanceApi(Request::create('/_setup/api/advance', 'POST', [], [], [], [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_X-Setup-Token' => 'tok',
        ], '{"profile":"admin_only","email":"evil@example.com","password":"secret"}'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(SetupProgress::PHASE_COMPLETED, $data['phase']);
        self::assertSame([], $this->createdAdmins);
    }

    public function testStaleWaitingAdminStepIsNotReachableOnAReopenedWizard(): void
    {
        // A run that was waiting on the admin form when the marker appeared (or a crafted progress record).
        $this->markers->markRequired('admin_only');
        $this->markers->markReopened();
        $this->progress->save((new SetupProgress())->with(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'admin_only',
            currentStepId: 'admin_user_0',
        ));
        $controller = $this->controller('tok');

        self::assertSame(200, $controller->index(Request::create('/_setup?token=tok', 'GET'))->getStatusCode());
        self::assertNull($this->lastContext['currentStep']);
        self::assertNull($this->lastContext['wizardForm'], 'no admin form is rendered');

        $post = $controller->index(Request::create('/_setup?token=tok', 'POST', [
            '_csrf_token' => 'token',
            'email'       => 'evil@example.com',
            'password'    => 'secret',
        ]));
        self::assertSame(200, $post->getStatusCode());
        self::assertNotNull($this->lastContext['error']);
        self::assertSame([], $this->createdAdmins);
    }

    public function testResetCommandKeepsTheReopenUnlessInitialIsPassed(): void
    {
        $this->markers->markDone();
        $this->markers->markReopened();
        $orchestrator = $this->orchestrator();

        (new CommandTester(new SetupResetCommand($orchestrator, $this->markers)))->execute(['--yes' => true]);
        self::assertFalse($this->markers->isDone());
        self::assertTrue($this->markers->isReopened());

        $tester = new CommandTester(new SetupResetCommand($orchestrator, $this->markers));
        $tester->execute(['--yes' => true, '--initial' => true]);
        self::assertFalse($this->markers->isReopened());
        self::assertStringContainsString('setup.reopened', $tester->getDisplay());
    }

    public function testHttpInputCannotSetTheReopenedState(): void
    {
        // An initial install: whatever the request carries, the marker file is the only source.
        $this->markers->markRequired('fresh_install');
        $controller = $this->controller('tok');
        $controller->index(Request::create('/_setup?token=tok&reopened=0', 'GET'));
        self::assertFalse($this->lastContext['reopened']);

        $this->markers->markReopened();
        $controller->index(Request::create('/_setup?token=tok&reopened=0', 'GET', [], [], [], ['HTTP_X-Setup-Reopened' => '0']));
        self::assertTrue($this->lastContext['reopened']);
    }

    /**
     * A done instance re-opened by a `reopen: true` detector on a main GET from the site gate.
     */
    private function reopenDoneInstanceThroughTheGate(): void
    {
        $this->markers->markDone();
        $detector = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return true;
            }

            public function getReason(): string
            {
                return 'catalogs empty';
            }
        };
        $evaluator = new SetupNeedEvaluator(
            [$detector],
            markers: $this->markers,
            reopener: new SetupWizardReopener($this->markers, $this->progress),
            reopenDetectors: [$detector],
        );
        self::assertTrue($evaluator->isSetupRequired(true));
        self::assertTrue($this->markers->isReopened());
        self::assertFalse($this->markers->isDone());
        // The wizard needs a reason to stay open after the reopen (an unfinished run / setup.required).
        $this->markers->markRequired('fresh_install');
    }

    private function orchestrator(): SetupOrchestrator
    {
        $test        = $this;
        $provisioner = new class($test) implements AdminUserProvisionerInterface {
            public function __construct(private readonly SetupReopenSafeStepsTest $test)
            {
            }

            public function adminExists(): bool
            {
                return false;
            }

            public function createAdmin(array $data): void
            {
                $this->test->recordAdmin($data);
            }
        };

        return new SetupOrchestrator(
            projectDir: $this->harnessProjectDir,
            stepFactory: new SetupStepFactory(new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30), $this->markers, $provisioner),
            progressStorage: $this->progress,
            markers: $this->markers,
            profiles: self::PROFILES,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function recordAdmin(array $data): void
    {
        $this->createdAdmins[] = $data;
    }

    private function controller(string $token): SetupWizardController
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context): string {
            $this->lastContext = $context;

            return 'html';
        });
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        return new SetupWizardController(
            $this->orchestrator(),
            new SetupNeedEvaluator([new MarkerFileDetector($this->markers, false, true)], true, markers: $this->markers),
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            $token,
            $csrf,
        );
    }

    /**
     * @return list<string>
     */
    private function stepIds(mixed $steps): array
    {
        self::assertIsArray($steps);

        return array_values(array_map(static fn (SetupStepInterface $s): string => $s->getId(), $steps));
    }
}
