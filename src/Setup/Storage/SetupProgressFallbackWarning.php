<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Once-per-request warning for the chain / cache_doctrine progress storages when their database layer fails and
 * they fall back to the filesystem / cache copy (1.37+).
 *
 * The exception message is not logged (it may carry host names, user names or SQL): only the class and code of
 * its innermost cause.
 * State is a single "already reported" flag, cleared by {@see reset()} (kernel.reset through the owning storage),
 * so a FrankenPHP worker reports again on the next request.
 *
 * @internal
 */
final class SetupProgressFallbackWarning implements ResetInterface
{
    private bool $reported = false;

    public function __construct(
        private readonly ?LoggerInterface $logger,
        private readonly string $storage,
        private readonly string $fallback,
    ) {
    }

    public function report(string $operation, Throwable $error): void
    {
        if ($this->reported || !$this->logger instanceof LoggerInterface) {
            return;
        }

        // @igor-ignore - per-request flag, cleared by reset() (kernel.reset through the owning storage).
        $this->reported = true;

        // The storages wrap the database error: report the innermost cause (driver class and vendor code).
        $cause = $error;
        while ($cause->getPrevious() instanceof Throwable) {
            $cause = $cause->getPrevious();
        }

        $this->logger->warning(
            'Setup progress: the database layer of the "{storage}" storage failed on {operation}; using the {fallback} copy only until it works (expected before the database exists).',
            [
                'storage'         => $this->storage,
                'operation'       => $operation,
                'fallback'        => $this->fallback,
                'exception_class' => $cause::class,
                'error_code'      => (string) $cause->getCode(),
            ],
        );
    }

    public function reset(): void
    {
        $this->reported = false;
    }
}
