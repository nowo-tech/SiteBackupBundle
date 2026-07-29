<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function explode;
use function htmlspecialchars;
use function is_string;
use function rtrim;
use function str_contains;
use function str_starts_with;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * While a restore is active, visitors see the loading UI instead of a broken half-restored site.
 */
final class RestoreRequestSubscriber
{
    public function __construct(
        private readonly bool $enabled,
        private readonly SiteBackupManager $manager,
        private readonly SiteBackupExclusionMatcher $exclusionMatcher,
        private readonly ?Environment $twig,
        private readonly string $template,
        private readonly int $statusCode = Response::HTTP_SERVICE_UNAVAILABLE,
        private readonly string $panelPathPrefix = '/_site_backup',
        private readonly string $defaultMessage = 'restore.page.message',
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();

        if ($this->panelPathPrefix !== '' && str_starts_with($path, $this->panelPathPrefix)) {
            return;
        }

        if ($this->exclusionMatcher->matches($request)) {
            return;
        }

        if ($request->attributes->getBoolean(ExcludeFromRestore::ROUTE_DEFAULT)) {
            return;
        }

        if ($this->controllerExcluded($request)) {
            return;
        }

        if (!$this->manager->isRestoreActive()) {
            return;
        }

        $progress = $this->manager->getRestoreProgress();
        $event->setResponse($this->createResponse($request, $progress));
    }

    private function controllerExcluded(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || $controller === '') {
            return false;
        }

        try {
            if (str_contains($controller, '::')) {
                [$class, $method] = explode('::', $controller, 2);
                $refMethod        = new ReflectionMethod($class, $method);
                if ($refMethod->getAttributes(ExcludeFromRestore::class) !== []) {
                    return true;
                }

                return $refMethod->getDeclaringClass()->getAttributes(ExcludeFromRestore::class) !== [];
            }

            if (class_exists($controller)) {
                $refClass = new ReflectionClass($controller);
                if ($refClass->getAttributes(ExcludeFromRestore::class) !== []) {
                    return true;
                }
                if ($refClass->hasMethod('__invoke')) {
                    return $refClass->getMethod('__invoke')->getAttributes(ExcludeFromRestore::class) !== [];
                }
            }
        } catch (ReflectionException) {
            return false;
        }

        return false;
    }

    private function createResponse(Request $request, RestoreProgress $progress): Response
    {
        if ($this->wantsJson($request)) {
            $response = new JsonResponse([
                'status'    => 'restoring',
                'phase'     => $progress->getPhase(),
                'percent'   => $progress->getPercent(),
                'message'   => $this->resolveMessage($progress),
                'backup_id' => $progress->getBackupId(),
                'error'     => $progress->getError(),
            ], $this->statusCode);
        } else {
            $response = new Response($this->renderHtml($progress), $this->statusCode);
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Retry-After', '30');

        return $response;
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->getRequestFormat(null) === 'json') {
            return true;
        }

        return $request->getPreferredFormat('html') === 'json'
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    private function renderHtml(RestoreProgress $progress): string
    {
        if ($this->twig instanceof Environment) {
            return $this->twig->render($this->template, [
                'progress'       => $progress,
                'defaultMessage' => $this->defaultMessage,
                'progressUrl'    => rtrim($this->panelPathPrefix, '/') . '/progress.json',
            ]);
        }

        $message = htmlspecialchars(
            $this->resolveMessage($progress),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $title = htmlspecialchars(
            $this->trans('restore.page.title'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $percent = htmlspecialchars((string) $progress->getPercent(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="5">'
            . '<title>' . $title . '</title></head><body>'
            . '<h1>' . $title . '</h1><p>' . $message . '</p>'
            . '<p><progress max="100" value="' . $percent . '"></progress> ' . $percent . '%</p>'
            . '</body></html>';
    }

    private function resolveMessage(RestoreProgress $progress): string
    {
        return $this->trans($progress->getMessage() ?? $this->defaultMessage);
    }

    private function trans(string $id): string
    {
        if ($this->translator instanceof TranslatorInterface) {
            return $this->translator->trans($id, [], 'NowoSiteBackupBundle');
        }

        return $id;
    }
}
