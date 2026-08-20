<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\TabStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SetupWizardControllerExtendedTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testRedirectWhenSetupNotRequired(): void
    {
        $evaluator    = new SetupNeedEvaluator([], true);
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker', 'write_done' => true]]],
        ]);
        $orchestrator->advance('fresh_install');

        $controller = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
        );

        self::assertSame(302, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testWizardRendersWaitingStepWithCurrentStep(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('admin_only');
        $evaluator    = new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true);
        $orchestrator = $this->createSetupOrchestrator([
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $controller = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
            $csrf,
        );

        self::assertSame(200, $controller->index(Request::create('/?profile=admin_only', 'GET'))->getStatusCode());

        $badCsrf = Request::create('/?profile=admin_only', 'POST', ['_csrf_token' => 'bad']);
        self::assertSame(200, $controller->index($badCsrf)->getStatusCode());

        $reset = Request::create('/?profile=admin_only', 'POST', ['reset' => '1', '_csrf_token' => 'token']);
        self::assertSame(200, $controller->index($reset)->getStatusCode());

        $postProfile = Request::create('/', 'POST', [
            'profile'     => 'admin_only',
            '_csrf_token' => 'token',
            'email'       => 'a@b.c',
            'password'    => 'secret',
        ]);
        self::assertContains($controller->index($postProfile)->getStatusCode(), [200, 302]);

        $noCsrfManager = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory(),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
        );
        self::assertSame(200, $noCsrfManager->index(Request::create('/', 'POST'))->getStatusCode());
    }

    public function testWizardWaitingStepAndFailedPost(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('waiting_profile');
        $evaluator = new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true);

        $orchestrator = $this->createSetupOrchestrator([
            'waiting_profile' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $controller = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
            $csrf,
        );

        $get = Request::create('/?profile=waiting_profile', 'GET');
        self::assertSame(200, $controller->index($get)->getStatusCode());

        $failOrch = $this->createSetupOrchestrator([
            'bad' => ['steps' => [['type' => 'requirements', 'extensions' => ['missing_ext_xyz_abc']]]],
        ]);
        $failController = new SetupWizardController(
            $failOrch,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
            $csrf,
        );
        $post = Request::create('/?profile=bad', 'POST', ['_csrf_token' => 'token']);
        self::assertSame(200, $failController->index($post)->getStatusCode());

        $api = Request::create('/api/advance', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad json');
        self::assertSame(200, $controller->advanceApi($api)->getStatusCode());

        $forbidden = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            'secret',
            $csrf,
        );
        self::assertSame(403, $forbidden->advanceApi(Request::create('/api/advance'))->getStatusCode());

        $headerToken      = Request::create('/api/advance', 'POST', ['profile' => 'waiting_profile'], [], [], ['HTTP_X-Setup-Token' => 'secret'], '{"profile":"waiting_profile"}');
        $headerController = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            'secret',
            $csrf,
        );
        self::assertSame(200, $headerController->advanceApi($headerToken)->getStatusCode());
    }

    public function testWizardRendersBootstrapSampleAndDatabaseUrlForms(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $twig     = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $profiles = [
            'bootstrap_only' => ['steps' => [['type' => 'bootstrap_mode']]],
            'sample_only'    => ['steps' => [['type' => 'sample_data', 'when' => 'opt_in']]],
            'dburl_only'     => ['steps' => [['type' => 'database_url', 'optional' => false]]],
            'dburl_tab'      => ['steps' => [['type' => 'database_url', 'optional' => false, 'template' => 'custom.html.twig']]],
        ];

        foreach ($profiles as $profile => $config) {
            $markers->markRequired($profile);
            $evaluator    = new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true);
            $orchestrator = $this->createSetupOrchestrator([$profile => $config]);
            $controller   = new SetupWizardController(
                $orchestrator,
                $evaluator,
                $this->createFormFactory($csrf),
                $twig,
                ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
                '/_setup',
                'Brand',
                null,
                $csrf,
            );

            $response = $controller->index(Request::create('/?profile=' . $profile, 'GET'));
            self::assertSame(200, $response->getStatusCode(), $profile);
        }
    }

    public function testCreateSetupFormCoversSampleDatabaseUrlAndContinue(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('admin_only');
        $evaluator    = new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true);
        $orchestrator = $this->createSetupOrchestrator([
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $controller = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $this->createFormFactory($csrf),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            null,
            $csrf,
        );

        $createForm = new ReflectionMethod(SetupWizardController::class, 'createSetupForm');
        $waiting    = new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            currentStepId: 'sample_data_0',
            answers: ['database_url' => 'mysql://app', 'sql_import_path' => 'dump.sql'],
        );

        $sample = new class {
            public function getId(): string
            {
                return 'sample_data_0';
            }
        };
        self::assertNotNull($createForm->invoke($controller, $waiting, $sample, []));

        $dbUrl = new DatabaseUrlStep('database_url_0', 'Database URL', false);
        self::assertNotNull($createForm->invoke(
            $controller,
            $waiting,
            $dbUrl,
            ['database connection failed'],
        ));

        $tabbed = new TabStep(new DatabaseUrlStep('database_url_1', 'Database URL', true));
        self::assertNotNull($createForm->invoke($controller, $waiting, $tabbed, []));

        $other = new class {
            public function getId(): string
            {
                return 'marker_0';
            }
        };
        self::assertNotNull($createForm->invoke($controller, $waiting, $other, []));

        $find = new ReflectionMethod(SetupWizardController::class, 'findCurrentStep');
        self::assertNull($find->invoke($controller, [new stdClass()], $waiting));
    }
}
