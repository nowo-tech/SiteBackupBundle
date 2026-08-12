<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use JsonException;
use Nowo\SiteBackupBundle\Form\Setup\AdminUserType;
use Nowo\SiteBackupBundle\Form\Setup\BootstrapModeType;
use Nowo\SiteBackupBundle\Form\Setup\ContinueType;
use Nowo\SiteBackupBundle\Form\Setup\DatabaseUrlType;
use Nowo\SiteBackupBundle\Form\Setup\ResetType;
use Nowo\SiteBackupBundle\Form\Setup\SampleDataType;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function hash_equals;
use function is_array;
use function is_string;
use function array_merge;
use function in_array;
use function json_decode;
use function method_exists;
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
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly array $templates,
        private readonly string $pathPrefix,
        private readonly string $brandName,
        private readonly ?string $setupToken,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?SetupPathPrefixResolver $pathPrefixResolver = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $pathPrefix = $this->effectivePathPrefix();

        if (!$this->needEvaluator->isSetupRequired() && $this->orchestrator->getProgress()->getPhase() === SetupProgress::PHASE_COMPLETED) {
            return new RedirectResponse('/');
        }

        if (!$this->isTokenValid($request)) {
            return new Response($this->twig->render($this->templates['setup_token'], $this->setupViewVars([
                'pathPrefix' => $pathPrefix,
                'brandName' => $this->brandName,
            ])), 403);
        }

        $profile = $request->query->getString('profile') ?: null;
        $progress = $this->orchestrator->getProgress();
        $error = null;
        $reasons = $this->needEvaluator->getReasons();
        $steps = $this->orchestrator->getSteps($progress->getProfile());
        $currentStep = $this->findCurrentStep($steps, $progress);
        $wizardForm = $this->createSetupForm($progress, $currentStep, $reasons);

        if ($request->isMethod('POST')) {
            if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
                $error = 'Invalid CSRF token.';
            } elseif (!$wizardForm instanceof FormInterface) {
                $error = 'Invalid setup request.';
            } else {
                $wizardForm->handleRequest($request);
                if (!$wizardForm->isSubmitted()) {
                    $wizardForm->submit($request->request->all(), false);
                }

                if (!$wizardForm->isSubmitted() || !$wizardForm->isValid()) {
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
                        return new RedirectResponse(rtrim($pathPrefix, '/') . '/done');
                    }
                    if ($progress->getPhase() === SetupProgress::PHASE_FAILED) {
                        $error = $progress->getError();
                    }
                    $steps = $this->orchestrator->getSteps($progress->getProfile());
                    $currentStep = $this->findCurrentStep($steps, $progress);
                    $wizardForm = $this->createSetupForm($progress, $currentStep, $reasons);
                }
            }
        } elseif ($progress->getPhase() === SetupProgress::PHASE_IDLE) {
            $progress = $this->orchestrator->advance($profile);
            if ($progress->getPhase() === SetupProgress::PHASE_COMPLETED) {
                return new RedirectResponse(rtrim($pathPrefix, '/') . '/done');
            }
            $steps = $this->orchestrator->getSteps($progress->getProfile());
            $currentStep = $this->findCurrentStep($steps, $progress);
            $wizardForm = $this->createSetupForm($progress, $currentStep, $reasons);
        }

        return new Response($this->twig->render($this->templates['setup_wizard'], $this->setupViewVars([
            'pathPrefix' => $pathPrefix,
            'brandName' => $this->brandName,
            'progress' => $progress,
            'steps' => $steps,
            'currentStep' => $currentStep,
            'reasons' => $reasons,
            'error' => $error,
            'csrfToken' => $this->csrfTokenManager?->getToken('nowo_site_backup_setup')->getValue(),
            'progressUrl' => rtrim($pathPrefix, '/') . '/api/progress',
            'advanceMode' => $this->orchestrator->getAdvanceMode($progress->getProfile()),
            'wizardForm' => $wizardForm?->createView(),
        ])));
    }

    public function done(): Response
    {
        return new Response($this->twig->render($this->templates['setup_done'], $this->setupViewVars([
            'pathPrefix' => $this->effectivePathPrefix(),
            'brandName' => $this->brandName,
            'progress' => $this->orchestrator->getProgress(),
        ])));
    }

    public function progress(): JsonResponse
    {
        return new JsonResponse($this->orchestrator->getProgress()->toArray());
    }

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
                // Keep form/request bag payload on malformed JSON.
            }
        }

        $progress = $this->orchestrator->advance(
            is_string($payload['profile'] ?? null) ? $payload['profile'] : null,
            new SetupStepInput($payload),
        );

        return new JsonResponse($progress->toArray());
    }

    private function effectivePathPrefix(): string
    {
        return $this->pathPrefixResolver?->resolve() ?? $this->pathPrefix;
    }

    /**
     * @param array<int, object> $steps
     */
    private function findCurrentStep(array $steps, SetupProgress $progress): ?object
    {
        foreach ($steps as $step) {
            if (!method_exists($step, 'getId')) {
                continue;
            }

            if ($step->getId() === $progress->getCurrentStepId()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param list<string> $reasons
     */
    private function createSetupForm(SetupProgress $progress, ?object $currentStep, array $reasons): ?FormInterface
    {
        if ($progress->getPhase() === SetupProgress::PHASE_FAILED) {
            return $this->formFactory->createNamed('', ResetType::class, null, $this->formOptions());
        }

        if ($progress->getPhase() !== SetupProgress::PHASE_WAITING) {
            return $this->formFactory->createNamed('', ContinueType::class, null, $this->formOptions());
        }

        if ($currentStep === null) {
            return null;
        }

        $stepId = method_exists($currentStep, 'getId') ? $currentStep->getId() : '';
        $answers = $progress->getAnswers();
        $dbFailed = in_array('database connection failed', $reasons, true);

        if (str_contains($stepId, 'bootstrap')) {
            return $this->formFactory->createNamed('', BootstrapModeType::class, [
                'sql_import_path' => is_string($answers['sql_import_path'] ?? null) ? $answers['sql_import_path'] : '',
            ], $this->formOptions());
        }

        if (str_contains($stepId, 'admin')) {
            return $this->formFactory->createNamed('', AdminUserType::class, [
                'email' => is_string($answers['admin_email'] ?? null) ? $answers['admin_email'] : '',
                'password' => '',
            ], $this->formOptions());
        }

        if (str_contains($stepId, 'sample')) {
            return $this->formFactory->createNamed('', SampleDataType::class, null, $this->formOptions());
        }

        if (str_contains($stepId, 'database_url')) {
            return $this->formFactory->createNamed('', DatabaseUrlType::class, [
                'database_url' => is_string($answers['database_url'] ?? null) ? $answers['database_url'] : '',
            ], $this->formOptions([
                'db_connection_failed' => $dbFailed,
            ]));
        }

        return $this->formFactory->createNamed('', ContinueType::class, null, $this->formOptions());
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

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function formOptions(array $options = []): array
    {
        return array_merge(['csrf_protection' => $this->csrfTokenManager instanceof CsrfTokenManagerInterface], $options);
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    private function setupViewVars(array $vars): array
    {
        $vars['layout_template'] = $this->templates['setup_layout']
            ?? '@NowoSiteBackupBundle/setup/layout.html.twig';

        return $vars;
    }
}
