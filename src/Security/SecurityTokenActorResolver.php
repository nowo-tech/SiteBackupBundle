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
 * The identifier goes through {@see self::normalize()}: control and format characters (tab and newline included) are
 * dropped and it is cut at {@see self::MAX_LENGTH}.
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

        $identifier = self::normalize($identifier);

        return $identifier !== '' ? $identifier : $this->fallback;
    }

    /**
     * An actor as stored in history / events: no C0 / DEL controls (tab and newline included), no Unicode format
     * characters (bidi overrides, zero-width), trimmed, at most MAX_LENGTH characters. '' when nothing is left.
     */
    public static function normalize(string $actor): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]|\p{Cf}/u', '', $actor);
        if ($clean === null) {
            // Not valid UTF-8: keep the ASCII printable characters only.
            $clean = (string) preg_replace('/[^\x20-\x7E]/', '', $actor);
        }

        return trim(mb_substr(trim($clean), 0, self::MAX_LENGTH));
    }
}
