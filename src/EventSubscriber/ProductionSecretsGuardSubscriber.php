<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function hash_equals;
use function in_array;
use function is_array;
use function is_string;
use function parse_str;
use function parse_url;
use function password_get_info;
use function rawurldecode;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

/**
 * Opt-in (`security_guard.enabled`) fail-closed check outside local environments.
 *
 * Refuses to serve main HTTP requests and to run console commands while the setup token,
 * the panel password hash or APP_SECRET are empty or one of the configured placeholder values,
 * so a misnamed / forgotten deployment cannot keep `/_setup` and the backup panel unlocked.
 *
 * `cache:clear`, `cache:warmup`, `assets:install` and `nowo:site-backup:hash-password` are
 * skipped by default so Docker image builds can warm the cache without runtime secrets.
 *
 * v1.20+: the panel hash must have a valid password-hash shape (a hash cut by Docker Compose `$`
 * interpolation is refused), `extra_secrets` adds app secrets (min length / placeholders) and
 * `dsn_credentials` requires a password (and no documented default credentials) in Redis / AMQP DSNs.
 *
 * Stateless: every request / command re-checks the (env-resolved) values — cheap string
 * compares, no latch kept in long-running workers.
 */
final readonly class ProductionSecretsGuardSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $localEnvironments
     * @param list<string> $forbiddenSetupTokens
     * @param list<string> $forbiddenPasswordHashes
     * @param list<string> $forbiddenAppSecrets
     * @param list<string> $skipConsoleCommands
     * @param list<array{label: string, value: string|null, min_length: int, forbidden: list<string>, required: bool}> $extraSecrets
     * @param list<array{label: string, value: string|null, schemes: list<string>, forbidden_credentials: list<string>, password_fallback: string|null}> $dsnCredentials
     */
    public function __construct(
        private string $environment,
        private array $localEnvironments = ['dev', 'test'],
        private bool $checkSetupToken = true,
        private ?string $setupToken = null,
        private array $forbiddenSetupTokens = [],
        private bool $checkPanelPassword = true,
        private ?string $panelPasswordHash = null,
        private array $forbiddenPasswordHashes = [],
        private bool $checkAppSecret = true,
        private ?string $appSecret = null,
        private array $forbiddenAppSecrets = [],
        private int $appSecretMinLength = 16,
        private array $skipConsoleCommands = [],
        private bool $checkPasswordHashShape = true,
        private array $extraSecrets = [],
        private array $dsnCredentials = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 1024],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->assertSafe();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if ($name !== null && in_array($name, $this->skipConsoleCommands, true)) {
            return;
        }

        $this->assertSafe();
    }

    /**
     * @throws RuntimeException when a non-local environment still uses empty / placeholder secrets
     */
    public function assertSafe(): void
    {
        if (in_array($this->environment, $this->localEnvironments, true)) {
            return;
        }

        if ($this->checkSetupToken) {
            $this->assertSecret(
                (string) $this->setupToken,
                $this->forbiddenSetupTokens,
                'nowo_site_backup.setup.setup_token (e.g. SITE_SETUP_TOKEN)',
                'Generate a random secret and open the wizard with ?token=….',
            );
        }

        if ($this->checkPanelPassword) {
            $this->assertSecret(
                (string) $this->panelPasswordHash,
                $this->forbiddenPasswordHashes,
                'nowo_site_backup.security.password_hash (e.g. SITE_BACKUP_PASSWORD_HASH)',
                'Generate a new hash with: bin/console nowo:site-backup:hash-password',
            );
            if ($this->checkPasswordHashShape && password_get_info(trim((string) $this->panelPasswordHash))['algo'] === null) {
                throw new RuntimeException('nowo_site_backup.security.password_hash is not a valid password hash (often cut by Docker Compose: a "$" in an env file starts a variable). Single-quote it in the env file (SITE_BACKUP_PASSWORD_HASH=\'$2y$…\') or write every "$" as "$$".');
            }
        }

        if ($this->checkAppSecret) {
            $secret = trim((string) $this->appSecret);
            $this->assertSecret($secret, $this->forbiddenAppSecrets, 'APP_SECRET', 'Generate one with e.g. openssl rand -hex 32.');
            if (strlen($secret) < $this->appSecretMinLength) {
                throw new RuntimeException(sprintf('APP_SECRET must be at least %d characters outside local environments.', $this->appSecretMinLength));
            }
        }

        foreach ($this->extraSecrets as $extra) {
            $this->assertExtraSecret($extra);
        }

        foreach ($this->dsnCredentials as $dsn) {
            $this->assertDsnCredentials($dsn);
        }
    }

    /**
     * @param array{label: string, value: string|null, min_length: int, forbidden: list<string>, required: bool} $extra
     */
    private function assertExtraSecret(array $extra): void
    {
        $value = trim((string) $extra['value']);
        if ($value === '' && !$extra['required']) {
            return;
        }
        $this->assertSecret($value, $extra['forbidden'], $extra['label'], 'Set a unique random value.');
        if (strlen($value) < $extra['min_length']) {
            throw new RuntimeException(sprintf('%s must be at least %d characters outside local environments.', $extra['label'], $extra['min_length']));
        }
    }

    /**
     * Only DSNs whose scheme is listed are checked (e.g. a doctrine:// queue is skipped).
     *
     * @param array{label: string, value: string|null, schemes: list<string>, forbidden_credentials: list<string>, password_fallback: string|null} $dsn
     */
    private function assertDsnCredentials(array $dsn): void
    {
        $value  = trim((string) $dsn['value']);
        $parts  = $value === '' ? false : parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, $dsn['schemes'], true)) {
            return;
        }

        $user     = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $auth = is_string($query['auth'] ?? null) ? trim($query['auth']) : '';
        if ($password === '' && $auth === '' && trim((string) $dsn['password_fallback']) === '') {
            throw new RuntimeException(sprintf('%s must carry a password outside local environments (%s://user:password@host or ?auth=…).', $dsn['label'], $scheme));
        }
        if ($password !== '' && in_array($user . ':' . $password, $dsn['forbidden_credentials'], true)) {
            throw new RuntimeException(sprintf('%s still uses documented default credentials (%s). Create a dedicated user with a unique password.', $dsn['label'], $user));
        }
    }

    /**
     * @param list<string> $forbidden
     */
    private function assertSecret(string $value, array $forbidden, string $label, string $hint): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException(sprintf('%s must be set outside local environments (%s). %s', $label, $this->environment, $hint));
        }

        foreach ($forbidden as $placeholder) {
            if ($placeholder !== '' && hash_equals($placeholder, $value)) {
                throw new RuntimeException(sprintf('%s is still a documented placeholder / local default. %s', $label, $hint));
            }
        }
    }
}
