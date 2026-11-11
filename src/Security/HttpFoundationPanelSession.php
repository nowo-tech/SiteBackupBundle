<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Adapter: the HttpFoundation session behind the framework-free {@see PanelSessionInterface}.
 *
 * @internal built by {@see PanelRequestContextFactory}
 */
final readonly class HttpFoundationPanelSession implements PanelSessionInterface
{
    public function __construct(
        private SessionInterface $session,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    public function regenerateId(): void
    {
        $this->session->migrate(true);
    }
}
