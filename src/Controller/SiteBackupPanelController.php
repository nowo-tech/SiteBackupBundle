<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

use function implode;

final class SiteBackupPanelController
{
    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly SiteBackupManager $manager,
        private readonly SiteBackupAccessGateInterface $accessGate,
        private readonly Environment $twig,
        private readonly array $templates,
        private readonly string $pathPrefix,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'nowo_site_backup_panel', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (!$this->accessGate->isAuthenticated($request)) {
            return $this->login($request);
        }

        $error   = null;
        $success = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfValid($request, 'nowo_site_backup_panel')) {
                $error = 'Invalid CSRF token.';
            } else {
                $action = $request->request->getString('action');
                try {
                    $success = match ($action) {
                        'create'        => $this->handleCreate($request),
                        'delete'        => $this->handleDelete($request),
                        'verify'        => $this->handleVerify($request),
                        'restore'       => $this->handleRestore($request),
                        'clear_restore' => $this->clearRestore(),
                        'logout'        => null,
                        default         => null,
                    };
                    if ($action === 'logout') {
                        $this->accessGate->logout($request);

                        return new RedirectResponse($this->pathPrefix);
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return new Response($this->twig->render($this->templates['panel_index'], [
            'backups'    => $this->manager->listBackups(),
            'progress'   => $this->manager->getRestoreProgress(),
            'history'    => $this->manager->history(30),
            'pathPrefix' => $this->pathPrefix,
            'error'      => $error,
            'success'    => $success,
            'csrfToken'  => $this->csrfTokenManager?->getToken('nowo_site_backup_panel')->getValue(),
        ]));
    }

    #[Route('/progress.json', name: 'nowo_site_backup_progress', methods: ['GET'])]
    public function progress(): JsonResponse
    {
        $progress = $this->manager->getRestoreProgress();

        return new JsonResponse($progress->toArray());
    }

    #[Route('/history', name: 'nowo_site_backup_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        if (!$this->accessGate->isAuthenticated($request)) {
            return new RedirectResponse($this->pathPrefix);
        }

        return new Response($this->twig->render($this->templates['panel_history'], [
            'history'    => $this->manager->history(100),
            'pathPrefix' => $this->pathPrefix,
        ]));
    }

    private function login(Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST') && $request->request->getString('action') === 'login') {
            if (!$this->isCsrfValid($request, 'nowo_site_backup_login')) {
                $error = 'Invalid CSRF token.';
            } elseif ($this->accessGate->authenticate($request, $request->request->getString('password'))) {
                return new RedirectResponse($this->pathPrefix);
            } else {
                $error = 'Invalid password.';
            }
        }

        return new Response($this->twig->render($this->templates['panel_login'], [
            'pathPrefix'        => $this->pathPrefix,
            'error'             => $error,
            'protectionEnabled' => $this->accessGate->isProtectionEnabled(),
            'csrfToken'         => $this->csrfTokenManager?->getToken('nowo_site_backup_login')->getValue(),
        ]), $error ? 401 : 200);
    }

    private function handleCreate(Request $request): string
    {
        $label    = $request->request->getString('label');
        $artifact = $this->manager->createBackup($label !== '' ? $label : null, 'panel');

        return 'Backup created: ' . $artifact->getId();
    }

    private function handleDelete(Request $request): string
    {
        $id = $request->request->getString('backup_id');
        $this->manager->deleteBackup($id, 'panel');

        return 'Backup deleted: ' . $id;
    }

    private function handleVerify(Request $request): string
    {
        $id     = $request->request->getString('backup_id');
        $result = $this->manager->verifyBackup($id);

        return $result['ok']
            ? 'Integrity OK for ' . $id
            : 'Integrity FAILED: ' . implode('; ', $result['errors']);
    }

    private function handleRestore(Request $request): string
    {
        $id = $request->request->getString('backup_id');
        $this->manager->restore($id, 'panel');

        return 'Restore finished for ' . $id;
    }

    private function clearRestore(): string
    {
        $this->manager->clearRestoreStatus();

        return 'Restore status cleared.';
    }

    private function isCsrfValid(Request $request, string $id): bool
    {
        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return true;
        }

        $token = $request->request->getString('_csrf_token');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $token));
    }
}
