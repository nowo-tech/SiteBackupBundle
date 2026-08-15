<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function is_string;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * When setup is required (and restore is not active), redirect visitors to the wizard.
 */
final class SetupRequestSubscriber
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly SetupNeedEvaluator $needEvaluator,
        private readonly SiteBackupManager $backupManager,
        private readonly SiteBackupExclusionMatcher $exclusionMatcher,
        private readonly string $setupPathPrefix = '/_setup',
        private readonly string $panelPathPrefix = '/_site_backup',
        private readonly array $enabledLocales = [],
        private readonly ?SetupPathPrefixResolver $pathPrefixResolver = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        if ($this->backupManager->isRestoreActive()) {
            return;
        }

        if (!$this->needEvaluator->isSetupRequired()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();

        if ($this->isSetupPath($path)) {
            return;
        }

        if ($this->panelPathPrefix !== '' && str_starts_with($path, $this->panelPathPrefix)) {
            return;
        }

        if ($this->exclusionMatcher->matches($request)) {
            return;
        }

        $target = $this->pathPrefixResolver?->resolve() ?? $this->setupPathPrefix;
        $token  = $request->query->get('token');
        if (is_string($token) && $token !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        }

        $event->setResponse(new RedirectResponse($target));
        $event->stopPropagation();
    }

    private function isSetupPath(string $path): bool
    {
        $prefix = rtrim($this->setupPathPrefix, '/');
        if ($prefix === '') {
            return false;
        }

        if (str_starts_with($path, $prefix)) {
            return true;
        }

        foreach ($this->enabledLocales as $locale) {
            if (str_starts_with($path, '/' . $locale . $prefix)) {
                return true;
            }
        }

        return false;
    }
}
