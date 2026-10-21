<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Contract;

use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Support\PageFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use Nowo\SiteBackupBundle\View\SetupTokenGate;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\Test\StubHost;
use Nowo\UiKitBundle\View\ListPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function class_exists;
use function dirname;
use function glob;
use function password_hash;
use function strpos;
use function substr_count;

use const PASSWORD_DEFAULT;

/**
 * REQ-UI-003: every panel and setup page extends a UiKit scaffold, fills the canonical blocks, ships no chrome of its
 * own (no <html>, CDN, inline handlers, framework classes outside the theme slots) and renders translated labels only.
 */
final class PageContractTest extends PageContractTestCase
{
    use CreatesSiteBackupTestHarness;

    protected function tearDown(): void
    {
        if (isset($this->harnessFs)) {
            $this->destroyHarness();
        }
    }

    public function testArchivesListRendersCellsDialogsAndFallbacks(): void
    {
        $backups = PageFixtures::backups();
        $html    = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/panel/index.html.twig', PageFixtures::index($backups, new RestoreProgress(false, 'completed', 100.0, 'restore.message.completed', $backups[0]->getId()), ['success' => ['Backup created: x']])))->html;

        self::assertStringContainsString('<code class="nowo-ui-code pc-code">20261018-101500-a1b2c3</code>', $html);
        self::assertStringContainsString('48 213 504 B', $html);
        self::assertSame(4, substr_count($html, '<dialog'), 'One restore + one delete dialog per archive.');
        self::assertStringContainsString('Restore backup 20261018-101500-a1b2c3?', $html);
        self::assertStringContainsString('pattern="20261018-101500-a1b2c3"', $html, 'Restore is type-to-confirm.');
        self::assertStringContainsString('nowo-ui-action__fallback" href="/_site_backup/restore/20261018-101500-a1b2c3"', $html);
        self::assertStringContainsString('nowo-ui-action__fallback" href="/_site_backup/delete/20261017-231000-d4e5f6"', $html);
        self::assertSame(8, substr_count($html, 'value="token-nowo_site_backup_panel"'), 'CSRF on verify (row form), restore and delete (dialog forms) of both rows, logout and clear status.');
        self::assertStringContainsString('data-phase="completed"', $html);
        self::assertStringContainsString('<input type="hidden" name="action" value="clear_restore">', $html);
        self::assertStringContainsString('Backup created: x', $html);
        self::assertStringNotContainsString('data-nowo-confirm=', $html, 'No 1.x confirm attribute / listener.');
        self::assertStringNotContainsString('<script', $html);
    }

    public function testRowActionsDisplayOverrideAndIdleRestoreStatus(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/panel/index.html.twig', PageFixtures::index(PageFixtures::backups(), null, [], 'text')))->html;

        self::assertStringNotContainsString('nowo-sbb-restore-status', $html, 'No status notice while idle.');
        self::assertStringNotContainsString('<span class="visually-hidden">Verify</span>', $html, 'panel.row_actions_display: text shows the labels.');
        self::assertStringContainsString('Verify', $html);
    }

    public function testAreaNavLinksBackupsAndHistory(): void
    {
        $nav = PageFixtures::view()->nav('history');
        self::assertCount(2, $nav->items);
        self::assertSame('/_site_backup/history', $nav->items[1]->href);
        self::assertTrue($nav->items[1]->active);
        self::assertFalse($nav->items[0]->active);

        $published = PageFixtures::view()->areaNav(SiteBackupView::AREA, ['current' => '/_site_backup/']);
        self::assertNotNull($published);
        self::assertTrue($published->items[0]->active);
        self::assertNull(PageFixtures::view()->areaNav('other'));
    }

    public function testConfirmPagesPostWithCsrf(): void
    {
        $restore = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/panel/confirm.html.twig', PageFixtures::confirm('restore')))->html;
        self::assertStringContainsString('data-nowo-ui-page="confirm"', $restore);
        self::assertStringContainsString('action="/_site_backup/"', $restore);
        self::assertStringContainsString('name="backup_id" value="20261018-101500-a1b2c3"', $restore);
        self::assertStringContainsString('value="token-nowo_site_backup_panel"', $restore);
        self::assertStringContainsString('required pattern="20261018-101500-a1b2c3"', $restore);
        self::assertStringContainsString('href="/_site_backup/"', $restore);

        $delete = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/panel/confirm.html.twig', PageFixtures::confirm('delete')))->html;
        self::assertStringContainsString('<input type="hidden" name="action" value="delete">', $delete);
        self::assertStringNotContainsString('pattern=', $delete);
    }

    public function testSetupWizardRendersStepsReasonsAndAutoAdvance(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/wizard.html.twig', PageFixtures::wizard('database_url')))->html;
        self::assertStringContainsString('data-nowo-ui-contract-layout="guest"', $html, 'Wizard on the guest layout.');
        self::assertStringContainsString('Step 3 of 5', $html);
        self::assertStringContainsString('The database is not reachable', $html);
        self::assertStringContainsString('formnovalidate', $html);
        self::assertStringContainsString('id="nowo-sbb-database-url"', $html);
        self::assertStringContainsString('placeholder="postgresql://user:pass@db:5432/app"', $html, 'Example URL from the catalogue, translated once.');
        self::assertStringContainsString('>Waiting for input</label>', $html, 'Literal step output is the progress label as given.');
        self::assertStringNotContainsString('setTimeout', $html);
        self::assertStringContainsString('data-testid="nowo-sbb-setup-progress"', $html, 'Progress through the UiKit progress partial.');
        self::assertMatchesRegularExpression('/<progress id="nowo-sbb-setup-progress"[^>]* max="100" value="\d+"/', $html);

        $running = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/wizard.html.twig', PageFixtures::wizard('running')))->html;
        self::assertStringContainsString("getElementById('nowo-sbb-auto-advance')", $running);

        $failed = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/wizard.html.twig', PageFixtures::wizard('failed')))->html;
        self::assertStringContainsString('Connection refused', $failed);
        self::assertStringContainsString('name="reset"', $failed);
    }

    /**
     * @return iterable<string, array{PageFixture}>
     */
    public static function translationPages(): iterable
    {
        foreach (self::pageFixtures() as $name => $fixture) {
            yield $name => [$fixture];
        }
    }

    /**
     * The form theme translates labels, help, placeholders and titles in the field domain: example values
     * are catalogue keys (`setup.database.url_placeholder`, `setup.bootstrap.sql_placeholder`), and literal step output,
     * exception messages and text the controller already translated are not looked up again (the profiler would log
     * them as missing messages). Every lookup of a page hits the catalogues.
     */
    #[DataProvider('translationPages')]
    public function testPageLooksUpNoMissingMessage(PageFixture $fixture): void
    {
        $twig      = $this->createTwig();
        $collector = $twig->getExtension(TranslationExtension::class)->getTranslator();
        assert($collector instanceof DataCollectorTranslator);
        $twig->render($fixture->template, $fixture->context);

        $missing = array_filter(
            $collector->getCollectedMessages(),
            static fn (array $message): bool => $message['state'] === DataCollectorTranslator::MESSAGE_MISSING,
        );
        self::assertSame([], array_values(array_map(static fn (array $message): string => $message['domain'] . ': ' . $message['id'], $missing)));
    }

    public function testCustomTabTemplateReceivesTheStepForm(): void
    {
        $context                = PageFixtures::wizard('admin_user');
        $context['currentStep'] = new class {
            public string $id       = 'demo_seed';
            public string $template = 'host/tab.html.twig';
        };
        $html = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/wizard.html.twig', $context))->html;

        self::assertStringContainsString('<p class="host-tab">fields: 3</p>', $html);
    }

    public function testLegacyLayoutsAreBridged(): void
    {
        $twig = $this->createTwig();
        $twig->addGlobal('nowo_ui_layout', ['admin' => StubHost::LAYOUT_ADMIN, 'guest' => StubHost::LAYOUT_GUEST]);
        $view = new SiteBackupView($twig, '/_site_backup', 'host/legacy_panel.html.twig', 'host/legacy_setup.html.twig');
        self::assertSame(SiteBackupView::LEGACY_PANEL_BRIDGE, $view->layout());
        self::assertSame(SiteBackupView::LEGACY_GUEST_BRIDGE, $view->guestLayout());
        self::assertSame(SiteBackupView::LEGACY_GUEST_BRIDGE, $view->guestLayoutOverride());

        $context                            = PageFixtures::historyPage(PageFixtures::history());
        $page                               = $context['page'];
        $context['page']                    = new ListPage($page->header, $page->columns, $page->rows, empty: $page->empty, bundle: $page->bundle, layout: SiteBackupView::LEGACY_PANEL_BRIDGE);
        $context['nowo_site_backup_legacy'] = ['panel' => 'host/legacy_panel.html.twig', 'guest' => null];
        $html                               = $twig->render('@NowoSiteBackupBundle/panel/history.html.twig', $context);

        self::assertStringStartsWith('<legacy-panel><head-css>', $html);
        self::assertStringContainsString('<host-css/>', $html);
        self::assertStringContainsString('css/nowo-ui.css', $html);
        self::assertStringContainsString('js/nowo-ui-confirm.js', $html);
        self::assertStringContainsString('data-nowo-ui-page="list"', $html);
        self::assertStringContainsString('restore_complete', $html);

        $canonical = new SiteBackupView($twig, '/_site_backup', StubHost::LAYOUT_ADMIN, '@NowoSiteBackupBundle/setup/layout.html.twig');
        self::assertSame(StubHost::LAYOUT_ADMIN, $canonical->layout());
        self::assertSame(StubHost::LAYOUT_GUEST, $canonical->guestLayout(), 'The removed 1.x bundle layout maps to the UiKit layout.');
        self::assertNull($canonical->guestLayoutOverride());
    }

    public function testBangNamespaceOverrideKeepsTheBundlePage(): void
    {
        $html = $this->renderPage(new PageFixture('host/history_override.html.twig', PageFixtures::historyPage([])))->html;

        self::assertStringContainsString('<p class="host-after">exported nightly</p>', $html);
        self::assertStringContainsString('data-nowo-ui-page="list"', $html);
    }

    /**
     * Full panel flow through the real controller and the real Twig pages: login (wrong password = 401), list,
     * create, confirm pages, history; pages behind the gate redirect to the login.
     */
    public function testPanelControllerFlow(): void
    {
        $this->initHarness();
        $manager = $this->createManager();
        $backup  = $manager->createBackup('nightly', 'cli');
        $csrf    = $this->csrf();
        $twig    = $this->createTwig();
        $panel   = new SiteBackupPanelController($manager, new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT), true), PageFixtures::formFactoryWith($csrf), $twig, [], '/_site_backup', $csrf, translator: $this->translator());
        $session = new Session(new MockArraySessionStorage());

        $login = $panel->index($this->request('GET', [], $session));
        self::assertSame(200, $login->getStatusCode());
        self::assertStringContainsString('data-nowo-ui-page="auth"', (string) $login->getContent());
        self::assertSame(302, $panel->create($this->request('GET', [], $session))->getStatusCode());

        $wrong = $panel->index($this->request('POST', ['action' => 'login', 'password' => 'nope', '_csrf_token' => 't'], $session));
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringContainsString('Invalid password.', (string) $wrong->getContent());

        self::assertSame(302, $panel->index($this->request('POST', ['action' => 'login', 'password' => 'secret', '_csrf_token' => 't'], $session))->getStatusCode());

        $list = (string) $panel->index($this->request('GET', [], $session))->getContent();
        self::assertStringContainsString($backup->getId(), $list);
        self::assertStringContainsString('nightly', $list);

        $created = (string) $panel->index($this->request('POST', ['action' => 'create', 'label' => 'pre-deploy', '_csrf_token' => 't'], $session))->getContent();
        self::assertStringContainsString('Backup created:', $created);
        self::assertStringContainsString('pre-deploy', $created);

        $form = $panel->create($this->request('GET', [], $session));
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="label"', (string) $form->getContent());
        self::assertStringContainsString('placeholder="pre-deploy"', (string) $form->getContent());
        self::assertStringContainsString('<form method="post" action="/_site_backup/" class="nowo-ui-form', (string) $form->getContent(), 'The create form posts to the panel index.');

        self::assertStringContainsString('data-nowo-ui-page="confirm"', (string) $panel->restoreConfirm($this->request('GET', [], $session), $backup->getId())->getContent());
        self::assertStringContainsString('value="delete"', (string) $panel->deleteConfirm($this->request('GET', [], $session), $backup->getId())->getContent());
        self::assertSame(404, $panel->deleteConfirm($this->request('GET', [], $session), 'missing')->getStatusCode());

        $history = (string) $panel->history($this->request('GET', [], $session))->getContent();
        self::assertStringContainsString('data-testid="nowo-sbb-history"', $history);
        self::assertStringContainsString('create', $history);

        $deleted = (string) $panel->index($this->request('POST', ['action' => 'delete', 'backup_id' => $backup->getId(), '_csrf_token' => 't'], $session))->getContent();
        self::assertStringContainsString('Backup deleted: ' . $backup->getId(), $deleted);

        $invalid = $panel->index($this->request('POST', ['action' => 'create', 'label' => 'x'], $session));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getStatusCode(), 'A create without CSRF goes back to the form.');
        self::assertStringContainsString('Invalid CSRF token.', (string) $invalid->getContent());
    }

    public function testMisconfiguredGateShowsTheErrorWithoutForm(): void
    {
        $this->initHarness();
        $panel    = new SiteBackupPanelController($this->createManager(), new PasswordSiteBackupAccessGate(null, true), PageFixtures::formFactoryWith($this->csrf()), $this->createTwig(), [], '/_site_backup', $this->csrf(), translator: $this->translator());
        $response = $panel->index($this->request('GET', [], new Session(new MockArraySessionStorage())));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('security.password_hash is not set', (string) $response->getContent());
        self::assertStringNotContainsString('type="password"', (string) $response->getContent());
    }

    /**
     * Cold start: no database, no users — only file markers and file progress (no DB-dependent service is involved).
     * Token gate → wizard (DATABASE_URL kept) → admin form → failure shown → done page, all rendered on the guest layout.
     */
    public function testSetupWizardRunsWithoutDatabase(): void
    {
        self::assertFalse(class_exists('Doctrine\\DBAL\\Connection'), 'The bundle test suite runs without doctrine/dbal.');

        $this->initHarness();
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('fresh_install');
        $orchestrator = $this->createSetupOrchestrator(['fresh_install' => ['steps' => [
            ['type' => 'database_url', 'optional' => true],
            ['type' => 'admin_user', 'skip_if_admin_exists' => false],
            ['type' => 'marker', 'write_done' => true],
        ]]]);
        $csrf       = $this->csrf();
        $controller = new SetupWizardController($orchestrator, new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true), PageFixtures::formFactoryWith($csrf), $this->createTwig(), [], '/_setup', 'Acme Shop', 's3cret', $csrf, translator: $this->translator());

        $gate = $controller->index(Request::create('/_setup'));
        self::assertSame(403, $gate->getStatusCode());
        self::assertStringContainsString('data-testid="nowo-sbb-token-gate"', (string) $gate->getContent());
        self::assertStringContainsString('X-Setup-Token', (string) $gate->getContent());

        // The optional DATABASE_URL step keeps the env value (skipped on the first advance); the admin form follows.
        $wizard = $controller->index(Request::create('/_setup?token=s3cret'));
        self::assertSame(200, $wizard->getStatusCode());
        $admin = (string) $wizard->getContent();
        self::assertStringContainsString('data-nowo-ui-contract-layout="guest"', $admin);
        self::assertStringContainsString('Database URL step skipped', $admin);
        self::assertStringContainsString('name="email"', $admin);
        self::assertStringContainsString('aria-current="step"><span>Admin user</span>', $admin);

        // The harness has no admin provisioner (no users table either): the step fails and the wizard shows why.
        $failed = (string) $controller->index(Request::create('/_setup?token=s3cret', 'POST', ['email' => 'admin@example.com', 'password' => 'Secret123!', '_csrf_token' => 't']))->getContent();
        self::assertStringContainsString('No AdminUserProvisionerInterface is configured', $failed);
        self::assertStringContainsString('name="reset"', $failed);

        $page = (string) $controller->done()->getContent();
        self::assertStringContainsString('data-testid="nowo-sbb-setup-done"', $page);
        self::assertStringContainsString('Setup complete', $page);
    }

    protected static function inlineTemplates(): array
    {
        return [
            // 1.x host layouts that render the bundle content blocks (bridged, deprecated).
            'host/legacy_panel.html.twig' => '<legacy-panel><head-css>{% block stylesheets %}<host-css/>{% endblock %}</head-css>{% block nowo_site_backup_panel_content %}{% endblock %}<js>{% block javascripts %}{% endblock %}</js></legacy-panel>',
            'host/legacy_setup.html.twig' => '<legacy-setup>{% block nowo_site_backup_content %}{% endblock %}</legacy-setup>',
            'host/tab.html.twig'          => '<p class="host-tab">fields: {{ form.children|length }}</p>',
            // Thin app override through the bang namespace (REQ-UI-003 "For applications" 4).
            'host/history_override.html.twig' => "{% extends '@!NowoSiteBackup/panel/history.html.twig' %}{% block nowo_ui_list_after %}<p class=\"host-after\">exported nightly</p>{% endblock %}",
        ];
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 't'));
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === 't');

        return $csrf;
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (glob(dirname(__DIR__, 3) . '/src/Resources/translations/NowoSiteBackupBundle.en.yaml') ?: [] as $file) {
            $translator->addResource('yaml', $file, 'en', 'NowoSiteBackupBundle');
        }

        return $translator;
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post, Session $session): Request
    {
        $request = Request::create('/_site_backup', $method, $post);
        $request->setSession($session);

        return $request;
    }

    public static function pageFixtures(): iterable
    {
        $backups = PageFixtures::backups();

        yield 'archives list' => new PageFixture('@NowoSiteBackupBundle/panel/index.html.twig', PageFixtures::index($backups, new RestoreProgress(false, 'completed', 100.0, 'restore.message.completed', $backups[0]->getId()), ['success' => ['Backup created: ' . $backups[0]->getId()]]));
        yield 'archives list (empty, failed restore, icon actions)' => new PageFixture('@NowoSiteBackupBundle/panel/index.html.twig', PageFixtures::index([], new RestoreProgress(false, 'failed', 40.0, 'restore.message.failed', 'x', 'Checksum mismatch for config/services.yaml'), ['error' => ['Invalid CSRF token.']], 'icon'));
        yield 'archives list (restore running)' => new PageFixture('@NowoSiteBackupBundle/panel/index.html.twig', PageFixtures::index($backups, new RestoreProgress(true, 'applying', 72.0, 'restore.message.applying', $backups[1]->getId()), [], 'icon_text'));
        yield 'history' => new PageFixture('@NowoSiteBackupBundle/panel/history.html.twig', PageFixtures::historyPage(PageFixtures::history()));
        yield 'history (empty)' => new PageFixture('@NowoSiteBackupBundle/panel/history.html.twig', PageFixtures::historyPage([]));
        yield 'create backup' => new PageFixture('@NowoSiteBackupBundle/panel/create.html.twig', PageFixtures::create());
        yield 'create backup (error)' => new PageFixture('@NowoSiteBackupBundle/panel/create.html.twig', PageFixtures::create(['error' => ['tar: config: Cannot open']]));
        yield 'restore confirm (no-JS)' => new PageFixture('@NowoSiteBackupBundle/panel/confirm.html.twig', PageFixtures::confirm('restore'));
        yield 'delete confirm (no-JS)' => new PageFixture('@NowoSiteBackupBundle/panel/confirm.html.twig', PageFixtures::confirm('delete'));
        yield 'login' => new PageFixture('@NowoSiteBackupBundle/panel/login.html.twig', PageFixtures::login());
        yield 'login (invalid password)' => new PageFixture('@NowoSiteBackupBundle/panel/login.html.twig', PageFixtures::login('Invalid password.'));
        yield 'login (misconfigured)' => new PageFixture('@NowoSiteBackupBundle/panel/login.html.twig', PageFixtures::login(misconfigured: true));
        yield 'login (unprotected)' => new PageFixture('@NowoSiteBackupBundle/panel/login.html.twig', PageFixtures::login(protected: false));
        foreach (['admin_user', 'database_url', 'bootstrap_mode', 'sample', 'running', 'failed', 'idle'] as $state) {
            yield 'setup wizard (' . $state . ')' => new PageFixture('@NowoSiteBackupBundle/setup/wizard.html.twig', PageFixtures::wizard($state));
        }
        yield 'setup done' => new PageFixture('@NowoSiteBackupBundle/setup/done.html.twig', PageFixtures::done());
        yield 'setup token' => new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token());
        yield 'setup token (no form)' => new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(false));
        yield 'setup token (illustration, custom steps)' => new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, SetupTokenGate::fromConfig(['illustration' => ['src' => 'illustrations/error-403.png', 'webp' => true, 'width' => 896, 'height' => 597], 'howto_steps' => ['setup.token.step_env', 'setup.token.step_header']])));
        yield 'setup token (illustration without WebP, no how-to)' => new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, SetupTokenGate::fromConfig(['illustration' => ['src' => 'illustrations/gate.png', 'webp' => false], 'howto_steps' => []])));
    }

    public function testTokenGateRendersTheIllustrationAndTheConfiguredSteps(): void
    {
        $gate = SetupTokenGate::fromConfig(['illustration' => ['src' => 'illustrations/error-403.png', 'webp' => true, 'width' => 896, 'height' => 597], 'howto_steps' => ['setup.token.step_url', 'Ask the on-call engineer.']]);
        $html = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, $gate)))->html;

        self::assertStringContainsString('<source type="image/webp" srcset="/assets/illustrations/error-403.webp">', $html);
        self::assertStringContainsString('<img class="nowo-sbb-token__img" src="/assets/illustrations/error-403.png" width="896" height="597" alt="Setup is locked until the setup token is provided" decoding="async" fetchpriority="high">', $html);
        self::assertLessThan(strpos($html, 'data-testid="nowo-sbb-token-gate"'), strpos($html, 'data-testid="nowo-sbb-token-illustration"'), 'The illustration sits above the auth panel.');
        self::assertStringContainsString('.nowo-sbb-token__img{', $html);
        self::assertSame(2, substr_count($html, '<li>'), 'Only the configured how-to steps.');
        self::assertStringContainsString('<li>Open /_setup?token=… with that value.</li>', $html);
        self::assertStringContainsString('<li>Ask the on-call engineer.</li>', $html, 'A literal step renders as given.');
        self::assertStringContainsString('name="token"', $html);
        self::assertStringNotContainsString('nonce=', $html);

        $request = Request::create('/_setup');
        $request->attributes->set('csp_nonce', 'n0nce');
        $nonced = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, $gate) + ['app' => (object) ['request' => $request], 'flash_messages' => []]))->html;
        self::assertStringContainsString('<style nonce="n0nce">.nowo-sbb-token__art{', $nonced);
    }

    public function testTokenGateDefaultsKeepTheThreeStepsWithoutIllustration(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, new SetupTokenGate())))->html;
        $bc   = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token()))->html;

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('.nowo-sbb-token__img{', $html);
        self::assertSame(3, substr_count($html, '<li>'));
        self::assertSame($html, $bc, 'Without tokenGate (host controller / 1.21 context) the page renders the defaults.');

        $none = $this->renderPage(new PageFixture('@NowoSiteBackupBundle/setup/token.html.twig', PageFixtures::token(true, SetupTokenGate::fromConfig(['illustration' => ['src' => 'illustrations/gate.webp'], 'howto_steps' => []]))))->html;
        self::assertStringNotContainsString('<source', $none, 'A WebP src needs no extra source.');
        self::assertStringContainsString('src="/assets/illustrations/gate.webp"', $none);
        self::assertStringNotContainsString('nowo-sbb-token-howto', $none, 'An empty step list drops the disclosure.');
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 3) . '/src/Resources/views';

        // Symfony registers a bundle's views as @NowoSiteBackup and @!NowoSiteBackup; TwigPathsPass adds @NowoSiteBackupBundle
        return ['NowoSiteBackupBundle' => $views, 'NowoSiteBackup' => $views, '!NowoSiteBackup' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }
}
