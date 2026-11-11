<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

/**
 * Framework-free view of the panel visitor's session, for {@see PanelAccessGateInterface} implementations.
 *
 * The bundle adapts the HttpFoundation session ({@see HttpFoundationPanelSession}); a DDD host gate only sees this
 * port. Values must be scalars or arrays of scalars.
 */
interface PanelSessionInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * New session id, same data (session fixation protection after a successful login).
     */
    public function regenerateId(): void;
}
