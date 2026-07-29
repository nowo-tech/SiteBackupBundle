<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use JsonException;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function hash_equals;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class SetupWizardController
{
    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly SetupOrchestrator $orchestrator,
        private readonly SetupNeedEvaluator $needEvaluator,
        private readonly Environment $twig,
        private readonly array $templates,
        private readonly string $pathPrefix,
        private readonly string $brandName,
        private readonly ?string $setupToken,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'nowo_site_backup_setup', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (!$this->needEvaluator->isSetupRequired() && $this->orchestrator->getProgress()->getPhase() === SetupProgress::PHASE_COMPLETED) {
            return new RedirectResponse('/');
        }

        if (!$this->isTokenValid($request)) {
            return new Response($this->twig->render($this->templates['setup_token'], [
                'pathPrefix' => $this->pathPrefix,
                'brandName'  => $this->brandName,
            ]), 403);
        }

        $profile  = $request->query->getString('profile') ?: null;
        $progress = $this->orchestrator->getProgress();
        $error    = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfValid($request)) {
                $error = 'Invalid CSRF token.';
            } else {
                $postedProfile = $request->request->getString('profile');
                if ($postedProfile !== '') {
                    $profile = $postedProfile;
                }
                $input = new SetupStepInput($request->request->all());
                if ($request->request->getString('reset') === '1') {
                    $this->orchestrator->resetProgress();
                }
                $progress = $this->orchestrator->advance($profile, $input);
                if ($progress->getPhase() === SetupProgress::PHASE_COMPLETED) {
                    return new RedirectResponse(rtrim($this->pathPrefix, '/') . '/done');
                }
                if ($progress->getPhase() === SetupProgress::PHASE_FAILED) {
                    $error = $progress->getError();
                }
            }
        } elseif ($progress->getPhase() === SetupProgress::PHASE_IDLE) {
            $progress = $this->orchestrator->advance($profile);
            if ($progress->getPhase() === SetupProgress::PHASE_COMPLETED) {
                return new RedirectResponse(rtrim($this->pathPrefix, '/') . '/done');
            }
        }

        $steps       = $this->orchestrator->getSteps($progress->getProfile());
        $currentStep = null;
        foreach ($steps as $step) {
            if ($step->getId() === $progress->getCurrentStepId()) {
                $currentStep = $step;
                break;
            }
        }

        // Always render the wizard shell. Form steps use partials inside setup_body
        // (do not switch to admin/sample/database templates that extend the full HTML
        // document — that path is redundant and fragile with Twig yield + inspectors).
        return new Response($this->twig->render($this->templates['setup_wizard'], [
            'pathPrefix'  => $this->pathPrefix,
            'brandName'   => $this->brandName,
            'progress'    => $progress,
            'steps'       => $steps,
            'currentStep' => $currentStep,
            'reasons'     => $this->needEvaluator->getReasons(),
            'error'       => $error,
            'csrfToken'   => $this->csrfTokenManager?->getToken('nowo_site_backup_setup')->getValue(),
            'progressUrl' => rtrim($this->pathPrefix, '/') . '/api/progress',
            'advanceMode' => $this->orchestrator->getAdvanceMode($progress->getProfile()),
        ]));
    }

    #[Route('/done', name: 'nowo_site_backup_setup_done', methods: ['GET'])]
    public function done(): Response
    {
        return new Response($this->twig->render($this->templates['setup_done'], [
            'pathPrefix' => $this->pathPrefix,
            'brandName'  => $this->brandName,
            'progress'   => $this->orchestrator->getProgress(),
        ]));
    }

    #[Route('/api/progress', name: 'nowo_site_backup_setup_progress', methods: ['GET'])]
    public function progress(): JsonResponse
    {
        return new JsonResponse($this->orchestrator->getProgress()->toArray());
    }

    #[Route('/api/advance', name: 'nowo_site_backup_setup_advance', methods: ['POST'])]
    public function advanceApi(Request $request): JsonResponse
    {
        if (!$this->isTokenValid($request)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $payload = $request->request->all();
        $content = $request->getContent();
        if ($content !== '' && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (JsonException) {
                // keep form/request bag
            }
        }

        $progress = $this->orchestrator->advance(
            is_string($payload['profile'] ?? null) ? $payload['profile'] : null,
            new SetupStepInput($payload),
        );

        return new JsonResponse($progress->toArray());
    }

    private function isTokenValid(Request $request): bool
    {
        if ($this->setupToken === null || $this->setupToken === '') {
            return true;
        }

        $provided = $request->query->getString('token');
        if ($provided === '') {
            $provided = $request->headers->get('X-Setup-Token', '');
        }

        return is_string($provided) && $provided !== '' && hash_equals($this->setupToken, $provided);
    }

    private function isCsrfValid(Request $request): bool
    {
        // Fail closed: setup POSTs require CSRF (REQ-UI-002 / SEC-004).
        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return false;
        }

        return $this->csrfTokenManager->isTokenValid(
            new CsrfToken('nowo_site_backup_setup', $request->request->getString('_csrf_token')),
        );
    }
}
