<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use Nowo\SiteBackupBundle\Form\Panel\CreateBackupType;
use Nowo\SiteBackupBundle\Form\Panel\PanelActionType;
use Nowo\SiteBackupBundle\Form\Panel\PanelLoginType;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\View\PanelPages;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function array_filter;
use function array_merge;
use function array_values;
use function ceil;
use function implode;
use function is_numeric;
use function is_string;
use function max;
use function strtr;

/**
 * Backup panel (REQ-UI-003, v1.21+): every page renders a UiKit view model built by {@see PanelPages} through
 * {@see SiteBackupView} (archives list, history, create form, no-JS restore / delete confirmations, guest login).
 */
final class SiteBackupPanelController
{
    private readonly SiteBackupView $view;

    private readonly PanelPages $pages;

    /**
     * @param array<string, string> $templates page id => template (web_ui.templates; 1.x templates.* keys accepted)
     */
    public function __construct(
        private readonly SiteBackupManager $manager,
        private readonly SiteBackupAccessGateInterface $accessGate,
        private readonly FormFactoryInterface $formFactory,
        Environment $twig,
        array $templates,
        private readonly string $pathPrefix,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?SiteBackupAccessCheckerInterface $accessChecker = null,
        private readonly bool $allowUnauthenticated = true,
        private readonly ?TranslatorInterface $translator = null,
        ?SiteBackupView $view = null,
    ) {
        $this->view  = $view ?? new SiteBackupView($twig, $pathPrefix, templates: $templates);
        $this->pages = new PanelPages($this->view);
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
                $error = $this->invalidCsrfMessage();
            } else {
                $submittedForm->handleRequest($request);
                if (!$submittedForm->isSubmitted() || !$submittedForm->isValid()) {
                    $error = $this->invalidCsrfMessage();
                } else {
                    try {
                        $success = match ($submittedAction) {
                            'create'        => $this->handleCreate($request),
                            'delete'        => $this->handleDelete($request),
                            'verify'        => $this->handleVerify($request),
                            'restore'       => $this->handleRestore($request),
                            'clear_restore' => $this->clearRestore(),
                            'logout'        => null,
                            default         => null,
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

        if ($submittedAction === 'create' && $submittedForm instanceof FormInterface && $error !== null) {
            // A failed create (invalid CSRF / archiver error) goes back to the form with the message.
            return $this->view->render('create', [
                'page'     => $this->pages->create($submittedForm->createView()),
                'messages' => $this->messages($error, null),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderIndex($this->messages($error, $success));
    }

    /**
     * Create-backup form (page/form). It posts to the panel index (action=create).
     */
    #[Route('/create', name: 'nowo_site_backup_create', methods: ['GET'])]
    public function create(Request $request): Response
    {
        if (($response = $this->guard($request)) instanceof Response) {
            return $response;
        }

        return $this->view->render('create', [
            'page'     => $this->pages->create($this->createCreateForm()->createView()),
            'messages' => [],
        ]);
    }

    /**
     * No-JS restore confirmation (page/confirm, type-to-confirm with the backup id), the fallback of the dialog.
     */
    #[Route('/restore/{id}', name: 'nowo_site_backup_restore_confirm', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function restoreConfirm(Request $request, string $id): Response
    {
        return $this->confirmPage($request, 'restore', $id);
    }

    /**
     * No-JS delete confirmation (page/confirm), the fallback of the dialog.
     */
    #[Route('/delete/{id}', name: 'nowo_site_backup_delete_confirm', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function deleteConfirm(Request $request, string $id): Response
    {
        return $this->confirmPage($request, 'delete', $id);
    }

    #[Route('/progress.json', name: 'nowo_site_backup_progress', methods: ['GET'])]
    public function progress(?Request $request = null): JsonResponse
    {
        $data = $this->manager->getRestoreProgress()->toArray();
        // v1.19+: an abandoned (stale) restore reports active=false so the loading page reloads into the site.
        $data['active'] = $this->manager->isRestoreActive();
        // v1.19+: progress messages are translation ids; serve the human text.
        if (is_string($data['message'] ?? null) && $data['message'] !== '' && $this->translator instanceof TranslatorInterface) {
            $data['message'] = $this->translator->trans($data['message'], [], 'NowoSiteBackupBundle');
        }

        // The restore page polls this anonymously: only panel users get the log / error / backup id.
        if (!$request instanceof Request || $this->denyUnlessRoleAccess() instanceof Response || !$this->accessGate->isAuthenticated($request)) {
            $data = [
                'active'  => $data['active'],
                'phase'   => $data['phase'],
                'percent' => $data['percent'],
                'message' => $data['message'],
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/history', name: 'nowo_site_backup_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        if (($response = $this->guard($request)) instanceof Response) {
            return $response;
        }

        return $this->view->render('history', [
            'page'     => $this->pages->history($this->manager->history(100)),
            'messages' => [],
        ]);
    }

    /**
     * @param array<string, list<string>> $messages
     */
    private function renderIndex(array $messages): Response
    {
        $backups  = array_values(array_filter($this->manager->listBackups(), static fn (mixed $b): bool => $b instanceof BackupArtifact));
        $progress = $this->manager->getRestoreProgress();

        return $this->view->render('index', [
            'page'          => $this->pages->index($backups, $this->createBackupFormViews($backups), $this->createPanelActionForm('logout')->createView()),
            'messages'      => $messages,
            'progress'      => $progress,
            'clear_restore' => new Action('custom', SiteBackupView::label('panel.index.clear_status'), $this->pathPrefix, method: 'POST', icon: 'x-circle', attributes: ['data-testid' => 'nowo-sbb-clear-restore'], form: $this->createPanelActionForm('clear_restore')->createView()),
        ]);
    }

    /**
     * @param 'delete'|'restore' $kind
     */
    private function confirmPage(Request $request, string $kind, string $id): Response
    {
        if (($response = $this->guard($request)) instanceof Response) {
            return $response;
        }

        if (!$this->manager->getBackup($id) instanceof BackupArtifact) {
            return new Response($this->message('panel.error.backup_not_found', 'Backup not found.'), Response::HTTP_NOT_FOUND);
        }

        return $this->view->render($kind . '_confirm', [
            'page'     => $this->pages->confirm($kind, $id, $this->createPanelActionForm($kind, $id)->createView()),
            'messages' => [],
        ]);
    }

    /**
     * Role check + password gate of the pages other than the index (the index shows the login form itself).
     */
    private function guard(Request $request): ?Response
    {
        if (($response = $this->denyUnlessRoleAccess()) instanceof Response) {
            return $response;
        }

        if (!$this->accessGate->isAuthenticated($request)) {
            return new RedirectResponse($this->pathPrefix);
        }

        return null;
    }

    /**
     * Messages of the last POST for the UiKit flashes partial ({@code flashes} input).
     *
     * @return array<string, list<string>>
     */
    private function messages(?string $error, ?string $success): array
    {
        $messages = [];
        if (is_string($error) && $error !== '') {
            $messages['error'] = [$error];
        }
        if (is_string($success) && $success !== '') {
            $messages['success'] = [$success];
        }

        return $messages;
    }

    private function login(Request $request): Response
    {
        $error      = null;
        $loginForm  = $this->createLoginForm();
        $retryAfter = null;

        if ($request->isMethod('POST') && $request->request->getString('action') === 'login') {
            if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
                $error = $this->invalidCsrfMessage();
            } else {
                $loginForm->handleRequest($request);
                if (!$loginForm->isSubmitted() || !$loginForm->isValid()) {
                    $error = $this->invalidCsrfMessage();
                } else {
                    try {
                        if ($this->accessGate->authenticate($request, $request->request->getString('password'))) {
                            return new RedirectResponse($this->pathPrefix);
                        }

                        $error = $this->message('panel.login.invalid_password', 'Invalid password.');
                    } catch (TooManyRequestsHttpException $e) {
                        // Login throttle (security.login_throttle): friendly 429, never a 500 / raw error page.
                        $retryAfter = $this->retryAfterSeconds($e);
                        $error      = $this->tooManyAttemptsMessage($retryAfter);
                    }
                }
            }
        }

        $misconfigured = $this->accessGate->isMisconfigured();
        $protected     = $this->accessGate->isProtectionEnabled();
        $pageError     = $misconfigured
            ? new Label('panel.login.misconfigured', SiteBackupView::BUNDLE, ['%option%' => 'security.password_hash', '%command%' => 'nowo:site-backup:hash-password'])
            : $error;

        $response = $this->view->render('login', [
            'page'      => $this->pages->login(!$misconfigured && $protected ? $loginForm->createView() : null, $pageError, $protected),
            'messages'  => [],
            'throttled' => $retryAfter !== null,
        ], $error || $misconfigured ? 401 : 200);

        if ($retryAfter !== null) {
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private function retryAfterSeconds(TooManyRequestsHttpException $e): int
    {
        $header = $e->getHeaders()['Retry-After'] ?? null;

        return is_numeric($header) ? max(1, (int) $header) : 60;
    }

    private function tooManyAttemptsMessage(int $retryAfter): string
    {
        $minutes = (string) max(1, (int) ceil($retryAfter / 60));

        return $this->message('panel.login.too_many_attempts', 'Too many login attempts. Try again in %minutes% minute(s).', ['%minutes%' => $minutes]);
    }

    private function invalidCsrfMessage(): string
    {
        return $this->message('panel.error.invalid_csrf', 'Invalid CSRF token.');
    }

    /**
     * Translated user-facing message (domain NowoSiteBackupBundle); English fallback without a translator (v1.18+).
     *
     * @param array<string, string> $parameters
     */
    private function message(string $id, string $fallback, array $parameters = []): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return strtr($fallback, $parameters);
        }

        return $this->translator->trans($id, $parameters, 'NowoSiteBackupBundle');
    }

    private function handleCreate(Request $request): string
    {
        $label    = $request->request->getString('label');
        $artifact = $this->manager->createBackup($label !== '' ? $label : null, 'panel');

        return $this->message('panel.flash.created', 'Backup created: %id%', ['%id%' => $artifact->getId()]);
    }

    private function handleDelete(Request $request): string
    {
        $id = $request->request->getString('backup_id');
        $this->manager->deleteBackup($id, 'panel');

        return $this->message('panel.flash.deleted', 'Backup deleted: %id%', ['%id%' => $id]);
    }

    private function handleVerify(Request $request): string
    {
        $id     = $request->request->getString('backup_id');
        $result = $this->manager->verifyBackup($id);

        return $result['ok']
            ? $this->message('panel.flash.verify_ok', 'Integrity OK for %id%', ['%id%' => $id])
            : $this->message('panel.flash.verify_failed', 'Integrity FAILED: %errors%', ['%errors%' => implode('; ', $result['errors'])]);
    }

    private function handleRestore(Request $request): string
    {
        $id = $request->request->getString('backup_id');
        $this->manager->restore($id, 'panel');

        return $this->message('panel.flash.restored', 'Restore finished for %id%', ['%id%' => $id]);
    }

    private function clearRestore(): string
    {
        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $this->manager->clearRestoreStatus();

        return $this->message('panel.flash.restore_cleared', 'Restore status cleared.');
    }

    private function denyUnlessRoleAccess(): ?Response
    {
        if ($this->allowUnauthenticated) {
            return null;
        }

        if (!$this->accessChecker instanceof SiteBackupAccessCheckerInterface || !$this->accessChecker->canAccess(null)) {
            return new Response($this->message('panel.error.access_denied', 'Access denied.'), Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @param list<BackupArtifact> $backups
     *
     * @return array<string, array{verify: FormView, restore: FormView, delete: FormView}>
     */
    private function createBackupFormViews(array $backups): array
    {
        $views = [];
        foreach ($backups as $backup) {
            $id         = $backup->getId();
            $views[$id] = [
                'verify'  => $this->createPanelActionForm('verify', $id)->createView(),
                'restore' => $this->createPanelActionForm('restore', $id)->createView(),
                'delete'  => $this->createPanelActionForm('delete', $id)->createView(),
            ];
        }

        return $views;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createCreateForm(): FormInterface
    {
        return $this->formFactory->createNamed('', CreateBackupType::class, null, $this->formOptions());
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createLoginForm(): FormInterface
    {
        return $this->formFactory->createNamed('', PanelLoginType::class, null, $this->formOptions());
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createPanelActionForm(string $action, ?string $backupId = null): FormInterface
    {
        return $this->formFactory->createNamed('', PanelActionType::class, null, $this->formOptions([
            'panel_action' => $action,
            'backup_id'    => $backupId,
        ]));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createSubmittedPanelForm(Request $request, string $action): FormInterface
    {
        return match ($action) {
            'create'                      => $this->createCreateForm(),
            'login'                       => $this->createLoginForm(),
            'verify', 'restore', 'delete' => $this->createPanelActionForm($action, $request->request->getString('backup_id')),
            default                       => $this->createPanelActionForm($action),
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
}
