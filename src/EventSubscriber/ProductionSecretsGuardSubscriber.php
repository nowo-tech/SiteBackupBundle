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
use function sprintf;
use function strlen;
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
        }

        if ($this->checkAppSecret) {
            $secret = trim((string) $this->appSecret);
            $this->assertSecret($secret, $this->forbiddenAppSecrets, 'APP_SECRET', 'Generate one with e.g. openssl rand -hex 32.');
            if (strlen($secret) < $this->appSecretMinLength) {
                throw new RuntimeException(sprintf('APP_SECRET must be at least %d characters outside local environments.', $this->appSecretMinLength));
            }
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
