<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function mb_substr;
use function preg_replace;
use function trim;

/**
 * Records the logged-in user identifier (`UserInterface::getUserIdentifier()`), or the fallback ("panel") when the
 * request has no authenticated user (password-gate only, or no SecurityBundle).
 *
 * The identifier is stored as the backup `createdBy` (1-128 printable characters): control characters are dropped and
 * it is cut at {@see self::MAX_LENGTH}.
 */
final readonly class SecurityTokenActorResolver implements ActorResolverInterface
{
    public const MAX_LENGTH = 128;

    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
        private ?string $fallback = self::PANEL,
    ) {
    }

    public function resolveActor(Request $request): ?string
    {
        $identifier = $this->tokenStorage?->getToken()?->getUserIdentifier();
        if ($identifier === null) {
            return $this->fallback;
        }

        $identifier = trim(mb_substr((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $identifier), 0, self::MAX_LENGTH));

        return $identifier !== '' ? $identifier : $this->fallback;
    }
}
