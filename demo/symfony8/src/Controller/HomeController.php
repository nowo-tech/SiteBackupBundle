<?php

declare(strict_types=1);

namespace App\Controller;

use App\Setup\DemoForceSetupNeedDetector;
use App\Setup\DemoSeedTabChecker;
use Nowo\SiteBackupBundle\Form\Setup\SetupTokenType;
use Nowo\SiteBackupBundle\View\SetupPages;
use Nowo\SiteBackupBundle\View\SetupTokenGate;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function unlink;

final class HomeController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%nowo_ui_kit.css_framework%')]
        private readonly string $cssFramework,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route(path: '/', name: 'homepage', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home.html.twig', [
            'css_framework'      => $this->cssFramework,
            'force_setup_active' => is_file($this->projectDir . '/' . DemoForceSetupNeedDetector::FLAG_RELATIVE),
            'seed_done'          => is_file($this->projectDir . '/' . DemoSeedTabChecker::SEED_RELATIVE),
        ]);
    }

    #[Route(path: '/health', name: 'app_health', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * Preview of the setup-token gate (HTTP 403 page when setup.setup_token is set) with the demo
     * setup.token_gate options: illustration (<picture> WebP + PNG fallback) and custom how-to steps (v1.22).
     */
    #[Route(path: '/demo/setup-token-gate', name: 'demo_setup_token_gate', methods: ['GET'])]
    public function setupTokenGate(SiteBackupView $view, SetupTokenGate $tokenGate): Response
    {
        $prefix = '/en/_setup';
        $form   = $this->container->get('form.factory')->createNamed('', SetupTokenType::class, null, ['action' => $prefix])->createView();

        return $view->render('setup_token', [
            'page'       => (new SetupPages($view, 'Site Backup Demo'))->token($form, $prefix),
            'tokenGate'  => $tokenGate,
            'pathPrefix' => $prefix,
            'brandName'  => 'Site Backup Demo',
            'messages'   => [],
        ]);
    }

    #[Route(path: '/demo/force-setup/on', name: 'demo_force_setup_on', methods: ['POST'])]
    public function forceSetupOn(Request $request): Response
    {
        $this->assertCsrf($request);
        $path = $this->projectDir . '/' . DemoForceSetupNeedDetector::FLAG_RELATIVE;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, "enabled-at=" . gmdate('c') . "\n");

        return $this->redirectToRoute('homepage');
    }

    #[Route(path: '/demo/force-setup/off', name: 'demo_force_setup_off', methods: ['POST'])]
    public function forceSetupOff(Request $request): Response
    {
        $this->assertCsrf($request);
        $path = $this->projectDir . '/' . DemoForceSetupNeedDetector::FLAG_RELATIVE;
        if (is_file($path)) {
            unlink($path);
        }

        return $this->redirectToRoute('homepage');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return;
        }

        $token = $request->request->getString('_csrf_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('demo_force_setup', $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
