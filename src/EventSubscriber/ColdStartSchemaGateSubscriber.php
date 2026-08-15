<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Setup\ColdStart\ColdStartRequestAttributes;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_bool;
use function rtrim;
use function str_starts_with;

/**
 * Redirects traffic to the setup wizard when the MySQL application schema is not reachable yet.
 */
final readonly class ColdStartSchemaGateSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $safePathPrefixes
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private SchemaExistenceCheckerInterface $schemaChecker,
        private SetupPathPrefixResolver $pathPrefixResolver,
        private string $setupPathPrefix = '/_setup',
        private array $safePathPrefixes = [],
        private array $enabledLocales = [],
        private bool $stopPropagation = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequestProbe', 35],
                ['onKernelRequestRedirect', 34],
                ['onKernelRequestStopLateListeners', 20],
            ],
        ];
    }

    public function onKernelRequestProbe(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->has(ColdStartRequestAttributes::SCHEMA_EXISTS)) {
            return;
        }

        $request->attributes->set(
            ColdStartRequestAttributes::SCHEMA_EXISTS,
            $this->schemaChecker->schemaExists(),
        );
    }

    public function onKernelRequestRedirect(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->schemaExists($event)) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($this->isSafePath($path) || $this->isSetupPath($path)) {
            return;
        }

        $target = $this->pathPrefixResolver->resolve();
        $event->setResponse(new RedirectResponse($target));

        if ($this->stopPropagation) {
            $event->stopPropagation();
        }
    }

    public function onKernelRequestStopLateListeners(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->stopPropagation || $this->schemaExists($event)) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!$this->isSafePath($path) && !$this->isSetupPath($path)) {
            return;
        }

        $event->stopPropagation();
    }

    private function schemaExists(RequestEvent $event): bool
    {
        $value = $event->getRequest()->attributes->get(ColdStartRequestAttributes::SCHEMA_EXISTS);

        return is_bool($value) && $value;
    }

    private function isSafePath(string $path): bool
    {
        foreach ($this->safePathPrefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isSetupPath(string $path): bool
    {
        $prefix = rtrim($this->setupPathPrefix, '/');
        if ($prefix === '') {
            return false;
        }

        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }

        foreach ($this->enabledLocales as $locale) {
            $localized = '/' . $locale . $prefix;
            if ($path === $localized || str_starts_with($path, $localized . '/')) {
                return true;
            }
        }

        return false;
    }
}
