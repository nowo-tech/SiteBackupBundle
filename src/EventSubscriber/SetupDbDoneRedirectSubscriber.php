<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Closes the setup wizard when {@see SetupDbDoneGuard} reports the instance is durably complete.
 */
final readonly class SetupDbDoneRedirectSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private SetupDbDoneGuard $setupDbDoneGuard,
        private string $setupPathPrefix = '/_setup',
        private array $enabledLocales = [],
        private string $redirectTarget = '/',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After SetupRequestSubscriber (~30) and host catalog redirects.
            KernelEvents::REQUEST => [['onKernelRequest', 3]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!$this->isSetupWizardPath($path)) {
            return;
        }

        if (!$this->setupDbDoneGuard->shouldCloseWizard()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->redirectTarget));
    }

    private function isSetupWizardPath(string $path): bool
    {
        $prefix = rtrim($this->setupPathPrefix, '/') ?: '/_setup';

        if ($this->pathMatchesWizard($path, $prefix)) {
            return true;
        }

        foreach ($this->enabledLocales as $locale) {
            if ($this->pathMatchesWizard($path, '/' . $locale . $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wizard index / advance only — leave {@code /setup/done} reachable after finish.
     */
    private function pathMatchesWizard(string $path, string $prefix): bool
    {
        if ($path === $prefix || $path === $prefix . '/') {
            return true;
        }

        return str_starts_with($path, $prefix . '/api');
    }
}
