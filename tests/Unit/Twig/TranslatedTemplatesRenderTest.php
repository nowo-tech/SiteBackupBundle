<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Controller\CreatesFormFactory;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Restore loading page (standalone, outside the UiKit page contract) and the translated access-denied response.
 * The panel / setup pages are covered by tests/Integration/Contract (UiKit PageContractTestCase, v1.21+).
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

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
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

    private function gate(bool $authenticated = true, bool $protection = true, bool $misconfigured = false): SiteBackupAccessGateInterface
    {
        $gate = $this->createMock(SiteBackupAccessGateInterface::class);
        $gate->method('isAuthenticated')->willReturn($authenticated);
        $gate->method('isProtectionEnabled')->willReturn($protection);
        $gate->method('isMisconfigured')->willReturn($misconfigured);

        return $gate;
    }
}
