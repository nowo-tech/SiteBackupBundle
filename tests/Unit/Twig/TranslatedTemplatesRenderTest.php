<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\Controller\CreatesFormFactory;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * v1.18 (spec 006): panel / setup / restore screens are fully translated (domain NowoSiteBackupBundle),
 * themed through --nowo-ui-* tokens and expose chrome blocks + panel config so hosts need no forks.
 */
final class TranslatedTemplatesRenderTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;
    use RendersBundleTemplates;

    private const PANEL_TEMPLATES = [
        'panel_index'   => '@NowoSiteBackupBundle/panel/index.html.twig',
        'panel_login'   => '@NowoSiteBackupBundle/panel/login.html.twig',
        'panel_history' => '@NowoSiteBackupBundle/panel/history.html.twig',
    ];

    private const SETUP_TEMPLATES = [
        'setup_wizard' => '@NowoSiteBackupBundle/setup/wizard.html.twig',
        'setup_done'   => '@NowoSiteBackupBundle/setup/done.html.twig',
        'setup_token'  => '@NowoSiteBackupBundle/setup/token.html.twig',
        'setup_layout' => '@NowoSiteBackupBundle/setup/layout.html.twig',
    ];

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testLoginIsTranslatedAndUsesUiKitClasses(): void
    {
        $twig = $this->createTwig($this->createManager(), 'es', cssFramework: 'bootstrap5');
        $html = (string) $this->panel($twig, $this->gate(authenticated: false))->index(Request::create('/'))->getContent();

        self::assertStringContainsString('<html lang="es"', $html);
        self::assertStringContainsString('<title>Acceso - Copia de seguridad del sitio</title>', $html);
        self::assertStringContainsString('Panel de copias de seguridad', $html);
        self::assertStringContainsString('>Contraseña<', $html);
        self::assertStringContainsString('Iniciar sesión</button>', $html);
        self::assertStringContainsString('form-control', $html);
        self::assertStringContainsString('nowo-ui-btn nowo-ui-btn--primary', $html);
        self::assertStringContainsString('btn-primary', $html);
        self::assertStringContainsString('>Historial</a>', $html);
        self::assertStringNotContainsString('Sign in', $html);
        self::assertStringNotContainsString('Password', $html);
    }

    public function testLoginNoticesAreTranslated(): void
    {
        $twig = $this->createTwig($this->createManager(), 'fr');

        $html = (string) $this->panel($twig, $this->gate(authenticated: false, misconfigured: true))->index(Request::create('/'))->getContent();
        self::assertStringContainsString("La protection par mot de passe est activée mais <code>security.password_hash</code> n'est pas défini", $html);
        self::assertStringContainsString('<code>nowo:site-backup:hash-password</code>', $html);
        self::assertStringNotContainsString('<form', $html);

        $html = (string) $this->panel($twig, $this->gate(authenticated: false, protection: false))->index(Request::create('/'))->getContent();
        self::assertStringContainsString('La protection par mot de passe est désactivée.', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testLoginErrorAndFlashesAreTranslated(): void
    {
        $twig    = $this->createTwig($this->createManager(), 'es');
        $request = Request::create('/', 'POST', ['action' => 'login', 'password' => 'x']);
        $html    = (string) $this->panel($twig, $this->gate(authenticated: false), translator: 'es')->index($request)->getContent();

        self::assertStringContainsString('role="alert">Token CSRF no válido.</div>', $html);
    }

    public function testIndexIsTranslatedWithTextActionsByDefault(): void
    {
        $manager = $this->createManager();
        $backup  = $manager->createBackup('pre', 'test');
        $twig    = $this->createTwig($manager, 'es');
        $html    = (string) $this->panel($twig, $this->gate())->index(Request::create('/'))->getContent();

        self::assertStringContainsString('<title>Panel de copias de seguridad</title>', $html);
        self::assertStringContainsString('Crear copia de seguridad', $html);
        self::assertStringContainsString('placeholder="antes-del-despliegue"', $html);
        self::assertStringContainsString('Crear copia integral</button>', $html);
        self::assertStringContainsString('Progreso de la restauración', $html);
        self::assertStringContainsString('<strong data-phase="idle">En espera</strong>', $html);
        self::assertStringContainsString('<th>Tamaño</th>', $html);
        self::assertStringContainsString('<code>' . $backup->getId() . '</code>', $html);
        self::assertStringContainsString('nowo-ui-row-actions--text', $html);
        self::assertStringContainsString('>Verificar</button>', $html);
        self::assertStringContainsString('>Restaurar</button>', $html);
        self::assertStringContainsString('>Eliminar</button>', $html);
        self::assertStringContainsString('data-nowo-confirm="¿Restaurar esta copia ahora? Los visitantes verán la página de carga."', $html);
        self::assertStringContainsString('data-nowo-confirm="¿Eliminar la copia de seguridad?"', $html);
        self::assertStringContainsString('nowo-ui-btn--danger', $html);
        self::assertStringContainsString('nowo-ui-table-wrap', $html);
        self::assertStringContainsString('Cerrar sesión</button>', $html);
        self::assertStringContainsString('window.__nowoSiteBackupConfirm', $html);
        self::assertStringNotContainsString('aria-hidden="true"></i>', $html);
        self::assertStringNotContainsString('Create integral backup', $html);
        self::assertStringNotContainsString('<form method="post" action="', $html, 'panel forms post back to the panel URL');
    }

    public function testIndexEmptyStateAndTranslatedSuccessFlash(): void
    {
        $twig = $this->createTwig($this->createManager(), 'de');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);
        $request = Request::create('/', 'POST', ['action' => 'clear_restore', '_csrf_token' => 'token']);

        $html = (string) $this->panel($twig, $this->gate(), translator: 'de', csrf: $csrf)->index($request)->getContent();

        self::assertStringContainsString('role="status">Wiederherstellungsstatus zurückgesetzt.</div>', $html);
        self::assertStringContainsString('Noch keine Backups.', $html);
    }

    public function testIndexIconActionsAndExtraClasses(): void
    {
        $manager = $this->createManager();
        $manager->createBackup('pre', 'test');
        $twig = $this->createTwig($manager, 'en', cssFramework: 'tailwind', rowActionsDisplay: 'icon', panelClasses: [
            'card'   => 'host-card',
            'table'  => 'data-table',
            'button' => 'host-btn',
            'input'  => 'host-input',
        ]);
        $html = (string) $this->panel($twig, $this->gate())->index(Request::create('/'))->getContent();

        self::assertStringContainsString('nowo-ui-row-actions--icon', $html);
        self::assertStringContainsString('title="Verify" aria-label="Verify"', $html);
        self::assertStringContainsString('<span class="visually-hidden">Restore</span>', $html);
        self::assertStringContainsString('<i class="bi bi-eye" aria-hidden="true"></i>', $html);
        self::assertStringContainsString('nowo-ui-card card host-card', $html);
        self::assertStringContainsString('data-table', $html);
        self::assertStringContainsString('host-btn', $html);
        self::assertStringContainsString('host-input', $html);
        self::assertStringContainsString('overflow-x-auto', $html);
    }

    public function testIndexKitDisplayFollowsUiKitGlobal(): void
    {
        $manager = $this->createManager();
        $manager->createBackup('pre', 'test');
        $twig = $this->createTwig($manager, 'en', rowActionsDisplay: 'kit');
        $twig->addGlobal('nowo_ui_kit_row_actions_display', 'icon_text');
        $html = (string) $this->panel($twig, $this->gate())->index(Request::create('/'))->getContent();

        self::assertStringContainsString('nowo-ui-row-actions--icon-text', $html);
        self::assertStringContainsString('<span class="nowo-ui-action__label">Delete</span>', $html);
    }

    public function testHistoryIsTranslated(): void
    {
        $manager = $this->createManager();
        $twig    = $this->createTwig($manager, 'nl');
        $panel   = $this->panel($twig, $this->gate());

        $html = (string) $panel->history(Request::create('/history'))->getContent();
        self::assertStringContainsString('<title>Geschiedenis — Siteback-up</title>', $html);
        self::assertStringContainsString('<th>Uitgevoerd door</th>', $html);
        self::assertStringContainsString('Geen geschiedenis.', $html);

        $manager->createBackup('pre', 'tester');
        $html = (string) $panel->history(Request::create('/history'))->getContent();
        self::assertStringContainsString('<td>tester</td>', $html);
        self::assertStringNotContainsString('Geen geschiedenis.', $html);
    }

    public function testPanelChromeBlocksCanBeOverriddenWithoutForking(): void
    {
        $twig = $this->createTwig($this->createManager(), 'en', overrides: [
            'host/login.html.twig' => <<<'TWIG'
                {% extends '@NowoSiteBackupBundle/panel/login.html.twig' %}
                {% block nowo_site_backup_panel_brand %}<img class="host-logo" alt="Host">{% endblock %}
                {% block nowo_site_backup_login_eyebrow %}<p class="eyebrow">Ops</p>{% endblock %}
                {% block nowo_site_backup_login_heading %}Ops console{% endblock %}
                {% block nowo_site_backup_login_intro %}<p class="lead">Separate ops password.</p>{% endblock %}
                {% block nowo_site_backup_login_after %}<p class="after">Need help?</p>{% endblock %}
                {% block nowo_site_backup_body_class %} host-body{% endblock %}
                TWIG,
        ]);
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            $this->gate(authenticated: false),
            $this->createFormFactory(),
            $twig,
            ['panel_login' => 'host/login.html.twig'] + self::PANEL_TEMPLATES,
            '/_site_backup',
        );
        $html = (string) $controller->index(Request::create('/'))->getContent();

        self::assertStringContainsString('<img class="host-logo" alt="Host">', $html);
        self::assertStringContainsString('<p class="eyebrow">Ops</p>', $html);
        self::assertStringContainsString('<h2>Ops console</h2>', $html);
        self::assertStringContainsString('<p class="lead">Separate ops password.</p>', $html);
        self::assertStringContainsString('<p class="after">Need help?</p>', $html);
        self::assertStringContainsString('class="nowo-ui-body host-body"', $html);
        self::assertStringContainsString('Sign in</button>', $html);
    }

    public function testPanelLayoutStylesUseUiKitTokens(): void
    {
        $twig = $this->createTwig($this->createManager());
        $html = (string) $this->panel($twig, $this->gate(authenticated: false))->index(Request::create('/'))->getContent();

        self::assertStringContainsString('background: var(--nowo-ui-primary, #2563eb)', $html);
        self::assertStringContainsString('color: var(--nowo-ui-primary-contrast, #fff)', $html);
        self::assertStringContainsString('var(--nowo-ui-surface, #fff)', $html);
        self::assertStringContainsString('var(--nowo-ui-border, #e2e8f0)', $html);
        self::assertStringContainsString('var(--nowo-ui-danger, #dc2626)', $html);
        self::assertStringContainsString('var(--nowo-ui-radius, 8px)', $html);
    }

    public function testFlashesPartialIsReusableByHostLayouts(): void
    {
        $twig = $this->createTwig($this->createManager(), 'en', cssFramework: 'bootstrap5');

        $html = $twig->render('@NowoSiteBackupBundle/panel/_flashes.html.twig', ['error' => 'Boom', 'success' => 'Yay']);
        self::assertStringContainsString('alert alert-danger nowo-ui-flash--err flash err" role="alert">Boom</div>', $html);
        self::assertStringContainsString('alert alert-success nowo-ui-flash--ok flash ok" role="status">Yay</div>', $html);
        self::assertSame('', trim($twig->render('@NowoSiteBackupBundle/panel/_flashes.html.twig', [])));
    }

    public function testSetupTokenGateIsTranslatedWithPasteForm(): void
    {
        $twig = $this->createTwig($this->createManager(), 'es');
        $html = (string) $this->setupController($twig, setupToken: 'secret')->index(Request::create('/_setup'))->getContent();

        self::assertStringContainsString('<title>Brand — Se requiere el token de configuración</title>', $html);
        self::assertStringContainsString('<h1>Se requiere el token de configuración</h1>', $html);
        self::assertStringContainsString('Pega el token de configuración para continuar.', $html);
        self::assertStringContainsString('method="get" action="/_setup"', $html);
        self::assertStringContainsString('name="token"', $html);
        self::assertStringContainsString('placeholder="Pega el token de configuración"', $html);
        self::assertStringContainsString('¿Dónde encuentro el token?', $html);
        self::assertStringContainsString('Abre /_setup?token=… con ese valor.', $html);
        self::assertStringContainsString('cabecera HTTP X-Setup-Token', $html);
        self::assertStringNotContainsString('_csrf_token', $html);
    }

    public function testSetupTokenPageWithoutFormFallsBackToBodyText(): void
    {
        $twig = $this->createTwig($this->createManager(), 'de');
        $html = $twig->render('@NowoSiteBackupBundle/setup/token.html.twig', ['brandName' => 'Acme', 'pathPrefix' => '/_setup']);

        self::assertStringContainsString('Öffnen Sie /_setup?token=… mit dem konfigurierten setup.setup_token.', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testSetupTokenBlocksCanBeOverridden(): void
    {
        $twig = $this->createTwig($this->createManager(), 'en', overrides: [
            'host/token.html.twig' => <<<'TWIG'
                {% extends '@NowoSiteBackupBundle/setup/token.html.twig' %}
                {% block nowo_site_backup_token_art %}<img class="art" alt="">{% endblock %}
                {% block nowo_site_backup_token_howto %}{% endblock %}
                {% block nowo_site_backup_logo %}<span class="logo"></span>{% endblock %}
                TWIG,
        ]);
        $controller = $this->setupController($twig, setupToken: 'secret', templates: ['setup_token' => 'host/token.html.twig'] + self::SETUP_TEMPLATES);
        $html       = (string) $controller->index(Request::create('/_setup'))->getContent();

        self::assertStringContainsString('<img class="art" alt="">', $html);
        self::assertStringContainsString('<div class="brand"><span class="logo"></span>Brand</div>', $html);
        self::assertStringNotContainsString('<details', $html);
        self::assertStringContainsString('name="token"', $html);
    }

    public function testSetupWizardIsTranslatedAndThemedThroughTokens(): void
    {
        $markers = new SetupMarkerManager($this->harnessProjectDir . '/_setup/required', $this->harnessProjectDir . '/_setup/done');
        $markers->markRequired('admin_only');
        $twig       = $this->createTwig($this->createManager(), 'pt');
        $controller = $this->setupController($twig, profiles: ['admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]]], markers: $markers);

        $html = (string) $controller->index(Request::create('/_setup?profile=admin_only'))->getContent();
        self::assertStringContainsString('<html lang="pt"', $html);
        self::assertStringContainsString('<title>Brand - Configuração</title>', $html);
        self::assertStringContainsString('>Email<', $html);
        self::assertStringContainsString('>Palavra-passe<', $html);
        self::assertStringContainsString('Criar administrador</button>', $html);
        self::assertStringContainsString('--nowo-ui-primary: #38bdf8;', $html);
        self::assertStringContainsString('--nowo-sbb-accent: var(--nowo-ui-primary, #38bdf8);', $html);
        self::assertStringContainsString('background: var(--nowo-sbb-accent);', $html);

        $html = (string) $controller->index(Request::create('/_setup?profile=admin_only', 'POST', ['email' => 'a@b.c']))->getContent();
        self::assertStringContainsString('<p class="err">Token CSRF inválido.</p>', $html);
    }

    public function testSetupDoneAndThemeTokenBlockOverride(): void
    {
        $twig = $this->createTwig($this->createManager(), 'it', overrides: [
            'host/done.html.twig' => <<<'TWIG'
                {% extends '@NowoSiteBackupBundle/setup/done.html.twig' %}
                {% block nowo_site_backup_theme_tokens %}<style>:root { --nowo-ui-primary: #0f766e; }</style>{% endblock %}
                {% block nowo_site_backup_html_class %} host-setup{% endblock %}
                TWIG,
        ]);

        $html = $twig->render('@NowoSiteBackupBundle/setup/done.html.twig', ['brandName' => 'Acme']);
        self::assertStringContainsString('<title>Acme — Pronto</title>', $html);
        self::assertStringContainsString('Configurazione completata', $html);

        $html = $twig->render('host/done.html.twig', ['brandName' => 'Acme']);
        self::assertStringContainsString('--nowo-ui-primary: #0f766e;', $html);
        self::assertStringNotContainsString('--nowo-ui-primary: #38bdf8;', $html);
        self::assertStringContainsString('nowo-ui-css-custom host-setup"', $html);
    }

    public function testRestorePagePhaseIsTranslated(): void
    {
        $twig = $this->createTwig($this->createManager(), 'fr');
        $html = $twig->render('@NowoSiteBackupBundle/restore/page.html.twig', [
            'progress'       => new RestoreProgress(true, RestoreProgress::PHASE_EXTRACTING, 40.0),
            'progressUrl'    => '/_site_backup/progress.json',
            'defaultMessage' => 'restore.page.message',
        ]);

        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('<div class="phase" data-phase="extracting">Extraction</div>', $html);
        self::assertStringContainsString('Restauration du site', $html);
    }

    public function testRestorePageShowsDefaultMessageAndTranslatedStepAndReloadsWhenInactive(): void
    {
        $twig = $this->createTwig($this->createManager(), 'es');
        $html = $twig->render('@NowoSiteBackupBundle/restore/page.html.twig', [
            'progress'       => new RestoreProgress(true, RestoreProgress::PHASE_VALIDATING, 10.0, RestoreOrchestrator::MESSAGE_VALIDATING),
            'progressUrl'    => '/_site_backup/progress.json',
            'defaultMessage' => 'restore.page.message',
        ]);

        // default_message (config) is rendered, the step message is a translated id, not an English literal.
        self::assertStringContainsString('Restaurando este sitio desde una copia verificada', $html);
        self::assertStringContainsString('Verificando la integridad del archivo…', $html);
        self::assertStringNotContainsString('restore.message.', $html);
        // Reloads on any inactive state (completed, failed, cleared, abandoned), not only "completed".
        self::assertStringContainsString('data.active === false', $html);
        self::assertStringNotContainsString("data.phase === 'completed'", $html);

        $html = $twig->render('@NowoSiteBackupBundle/restore/page.html.twig', [
            'progress'       => new RestoreProgress(true, RestoreProgress::PHASE_PREPARING, 1.0),
            'progressUrl'    => '/_site_backup/progress.json',
            'defaultMessage' => 'Custom literal maintenance text',
        ]);
        self::assertStringContainsString('Custom literal maintenance text', $html);
        self::assertStringNotContainsString('data-step', $html);
    }

    public function testAccessDeniedIsTranslated(): void
    {
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            $this->gate(),
            $this->createFormFactory(),
            $this->createTwig($this->createManager()),
            self::PANEL_TEMPLATES,
            '/_site_backup',
            allowUnauthenticated: false,
            translator: $this->createTranslator('es'),
        );

        $response = $controller->index(Request::create('/'));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Acceso denegado.', $response->getContent());
    }

    private function panel(Environment $twig, SiteBackupAccessGateInterface $gate, ?string $translator = null, ?CsrfTokenManagerInterface $csrf = null): SiteBackupPanelController
    {
        return new SiteBackupPanelController(
            $this->createManager(),
            $gate,
            $this->createFormFactory($csrf),
            $twig,
            self::PANEL_TEMPLATES,
            '/_site_backup',
            $csrf,
            null,
            true,
            $translator !== null ? $this->createTranslator($translator) : null,
        );
    }

    private function gate(bool $authenticated = true, bool $protection = true, bool $misconfigured = false): SiteBackupAccessGateInterface
    {
        $gate = $this->createMock(SiteBackupAccessGateInterface::class);
        $gate->method('isAuthenticated')->willReturn($authenticated);
        $gate->method('isProtectionEnabled')->willReturn($protection);
        $gate->method('isMisconfigured')->willReturn($misconfigured);

        return $gate;
    }

    /**
     * @param array<string, array{steps: list<array<string, mixed>>}> $profiles
     * @param array<string, string> $templates
     */
    private function setupController(
        Environment $twig,
        ?string $setupToken = null,
        array $profiles = ['fresh_install' => ['steps' => [['type' => 'marker']]]],
        ?SetupMarkerManager $markers = null,
        array $templates = self::SETUP_TEMPLATES,
    ): SetupWizardController {
        $markers ??= new SetupMarkerManager($this->harnessProjectDir . '/_setup/required', $this->harnessProjectDir . '/_setup/done');
        if (!$markers->isRequiredMarked()) {
            $markers->markRequired((string) array_key_first($profiles));
        }

        return new SetupWizardController(
            $this->createSetupOrchestrator($profiles),
            new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true),
            $this->createFormFactory(),
            $twig,
            $templates,
            '/_setup',
            'Brand',
            $setupToken,
            translator: $this->createTranslator((string) $twig->getGlobals()['app']->request->getLocale()),
        );
    }
}
