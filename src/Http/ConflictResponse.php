<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Http;

use Symfony\Component\HttpFoundation\Response;

use function max;

/**
 * Lock-conflict answer of the bundle's own routes (1.37+, REQ-RUNTIME-003 rule 6).
 *
 * The bundle takes its locks before it changes anything, so every conflict it answers is `CONFLICT_001` (retry is
 * safe): status 409 (or the configured 503), `Retry-After` and `X-Conflict-Code: CONFLICT_001`. Domain codes never go in
 * that header. Host routes are never answered.
 */
final class ConflictResponse
{
    public const HEADER = 'X-Conflict-Code';

    /** Nothing was committed: retrying is safe. */
    public const RETRY_SAFE = 'CONFLICT_001';

    /** Part of the request was committed: read again, do not repeat (not produced by this bundle today). */
    public const PARTIALLY_COMMITTED = 'CONFLICT_002';

    /**
     * 1.37+ (audit UT-1): the lock store failed, so no lock could be taken and nothing was done. Not a conflict: 503 +
     * `Retry-After`, no `X-Conflict-Code`.
     */
    public static function unavailable(Response $response, int $retryAfterSeconds): Response
    {
        $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', (string) max(1, $retryAfterSeconds));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function apply(Response $response, int $status, int $retryAfterSeconds): Response
    {
        $response->setStatusCode($status === Response::HTTP_SERVICE_UNAVAILABLE ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_CONFLICT);
        $response->headers->set('Retry-After', (string) max(1, $retryAfterSeconds));
        $response->headers->set(self::HEADER, self::RETRY_SAFE);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
