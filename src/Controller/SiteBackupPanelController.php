<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use Nowo\SiteBackupBundle\Form\Panel\CreateBackupType;
use Nowo\SiteBackupBundle\Form\Panel\PanelActionType;
use Nowo\SiteBackupBundle\Form\Panel\PanelLoginType;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;
use Twig\Environment;

use function array_merge;
use function implode;
use function method_exists;

final class SiteBackupPanelController
{
    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly SiteBackupManager $manager,
        private readonly SiteBackupAccessGateInterface $accessGate,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly array $templates,
        private readonly string $pathPrefix,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?SiteBackupAccessCheckerInterface $accessChecker = null,
        private readonly bool $allowUnauthenticated = true,
    ) {
    }

    #[Route('', name: 'nowo_site_backup_panel', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (($response = $this->denyUnlessRoleAccess()) instanceof Response) {
            return $response;
        }

        if (!$this->accessGate->isAuthenticated($request)) {
            return $this->login($request);
        }

        $error           = null;
        $success         = null;
        $submittedAction = null;
        $submittedForm   = null;

        if ($request->isMethod('POST')) {
            $submittedAction = $request->request->getString('action');
            $submittedForm   = $this->createSubmittedPanelForm($request, $submittedAction);

            if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
                $error = 'Invalid CSRF token.';
            } else {
                $submittedForm->handleRequest($request);
                if (!$submittedForm->isSubmitted() || !$submittedForm->isValid()) {
                    $error = 'Invalid CSRF token.';
                } else {
                    try {
                        $success = match ($submittedAction) {
                            'create' => $this->handleCreate($request),
                            'delete' => $this->handleDelete($request),
                            'verify' => $this->handleVerify($request),
                            'restore' => $this->handleRestore($request),
                            'clear_restore' => $this->clearRestore(),
                            'logout' => null,
                            default => null,
                        };
                        if ($submittedAction === 'logout') {
                            $this->accessGate->logout($request);

                            return new RedirectResponse($this->pathPrefix);
                        }
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
            }
        }

        $backups = $this->manager->listBackups();

        return new Response($this->twig->render($this->templates['panel_index'], [
            'backups' => $backups,
            'progress' => $this->manager->getRestoreProgress(),
            'history' => $this->manager->history(30),
            'pathPrefix' => $this->pathPrefix,
            'error' => $error,
            'success' => $success,
            'csrfToken' => $this->csrfTokenManager?->getToken('nowo_site_backup_panel')->getValue(),
            'createForm' => $this->resolveCreateFormView($submittedAction, $submittedForm),
            'clearRestoreForm' => $this->createPanelActionForm('clear_restore')->createView(),
            'logoutForm' => $this->createPanelActionForm('logout')->createView(),
            'backupForms' => $this->createBackupFormViews($backups),
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
        if (($response = $this->denyUnlessRoleAccess()) instanceof Response) {
            return $response;
        }

        if (!$this->accessGate->isAuthenticated($request)) {
            return new RedirectResponse($this->pathPrefix);
        }

        return new Response($this->twig->render($this->templates['panel_history'], [
            'history' => $this->manager->history(100),
            'pathPrefix' => $this->pathPrefix,
        ]));
    }

    private function login(Request $request): Response
    {
        $error     = null;
        $loginForm = $this->createLoginForm();

        if ($request->isMethod('POST') && $request->request->getString('action') === 'login') {
            if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
                $error = 'Invalid CSRF token.';
            } else {
                $loginForm->handleRequest($request);
                if (!$loginForm->isSubmitted() || !$loginForm->isValid()) {
                    $error = 'Invalid CSRF token.';
                } elseif ($this->accessGate->authenticate($request, $request->request->getString('password'))) {
                    return new RedirectResponse($this->pathPrefix);
                } else {
                    $error = 'Invalid password.';
                }
            }
        }

        return new Response($this->twig->render($this->templates['panel_login'], [
            'pathPrefix' => $this->pathPrefix,
            'error' => $error,
            'protectionEnabled' => $this->accessGate->isProtectionEnabled(),
            'misconfigured' => $this->accessGate->isMisconfigured(),
            'csrfToken' => $this->csrfTokenManager?->getToken('nowo_site_backup_login')->getValue(),
            'loginForm' => $loginForm->createView(),
        ]), $error || $this->accessGate->isMisconfigured() ? 401 : 200);
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

    private function denyUnlessRoleAccess(): ?Response
    {
        if ($this->allowUnauthenticated) {
            return null;
        }

        if (!$this->accessChecker instanceof SiteBackupAccessCheckerInterface || !$this->accessChecker->canAccess(null)) {
            return new Response('Access denied.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @param array<int, object> $backups
     *
     * @return array<string, array<string, FormView>>
     */
    private function createBackupFormViews(array $backups): array
    {
        $views = [];
        foreach ($backups as $backup) {
            if (!method_exists($backup, 'getId')) {
                continue;
            }

            $id = $backup->getId();
            $views[$id] = [
                'verify' => $this->createPanelActionForm('verify', $id)->createView(),
                'restore' => $this->createPanelActionForm('restore', $id)->createView(),
                'delete' => $this->createPanelActionForm('delete', $id)->createView(),
            ];
        }

        return $views;
    }

    private function createCreateForm(): FormInterface
    {
        return $this->formFactory->createNamed('', CreateBackupType::class, null, $this->formOptions());
    }

    private function createLoginForm(): FormInterface
    {
        return $this->formFactory->createNamed('', PanelLoginType::class, null, $this->formOptions());
    }

    private function createPanelActionForm(string $action, ?string $backupId = null): FormInterface
    {
        return $this->formFactory->createNamed('', PanelActionType::class, null, $this->formOptions([
            'action' => $action,
            'backup_id' => $backupId,
        ]));
    }

    private function createSubmittedPanelForm(Request $request, string $action): FormInterface
    {
        return match ($action) {
            'create' => $this->createCreateForm(),
            'login' => $this->createLoginForm(),
            'verify', 'restore', 'delete' => $this->createPanelActionForm($action, $request->request->getString('backup_id')),
            default => $this->createPanelActionForm($action),
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function formOptions(array $options = []): array
    {
        return array_merge(['csrf_protection' => $this->csrfTokenManager instanceof CsrfTokenManagerInterface], $options);
    }

    private function resolveCreateFormView(?string $submittedAction, ?FormInterface $submittedForm): FormView
    {
        if ($submittedAction === 'create' && $submittedForm instanceof FormInterface) {
            return $submittedForm->createView();
        }

        return $this->createCreateForm()->createView();
    }
}
