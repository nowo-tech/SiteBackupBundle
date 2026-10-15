<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\EventSubscriber;

use Nowo\SiteBackupBundle\EventSubscriber\ProductionSecretsGuardSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ProductionSecretsGuardSubscriberTest extends TestCase
{
    private const SAFE_SECRET = 'a3f1c0de9b8e7d6c5b4a39281706f5e4';

    /** bcrypt-shaped (60 chars) so password_get_info() recognises it. */
    private const VALID_HASH = '$2y$12$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyz01234';

    /**
     * @param array<string, mixed> $overrides
     */
    private function guard(array $overrides = []): ProductionSecretsGuardSubscriber
    {
        $args = array_merge([
            'environment'             => 'prod',
            'localEnvironments'       => ['dev', 'test'],
            'checkSetupToken'         => true,
            'setupToken'              => 'random-setup-token',
            'forbiddenSetupTokens'    => ['app-local-setup'],
            'checkPanelPassword'      => true,
            'panelPasswordHash'       => self::VALID_HASH,
            'forbiddenPasswordHashes' => ['$2y$12$localhash'],
            'checkAppSecret'          => true,
            'appSecret'               => self::SAFE_SECRET,
            'forbiddenAppSecrets'     => ['ChangeMe'],
            'appSecretMinLength'      => 16,
            'skipConsoleCommands'     => ['cache:clear'],
        ], $overrides);

        return new ProductionSecretsGuardSubscriber(...$args);
    }

    public function testSubscribedEvents(): void
    {
        $events = ProductionSecretsGuardSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
    }

    public function testSafeProductionPasses(): void
    {
        $this->guard()->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testLocalEnvironmentIsSkipped(): void
    {
        $this->guard(['environment' => 'dev', 'setupToken' => null, 'appSecret' => ''])->assertSafe();
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unsafe(): iterable
    {
        yield 'empty token' => [['setupToken' => ' '], 'setup_token'];
        yield 'placeholder token' => [['setupToken' => 'app-local-setup'], 'placeholder'];
        yield 'empty hash' => [['panelPasswordHash' => null], 'password_hash'];
        yield 'placeholder hash' => [['panelPasswordHash' => '$2y$12$localhash'], 'placeholder'];
        yield 'empty secret' => [['appSecret' => null], 'APP_SECRET'];
        yield 'placeholder secret' => [['appSecret' => 'ChangeMe'], 'placeholder'];
        yield 'short secret' => [['appSecret' => 'short-secret'], 'at least 16'];
        yield 'staging is not local' => [['environment' => 'staging', 'setupToken' => ''], 'setup_token'];
        yield 'hash cut by compose' => [['panelPasswordHash' => '$2y$13'], 'not a valid password hash'];
        yield 'extra secret placeholder' => [['extraSecrets' => [self::extra('!ChangeMe!')]], 'placeholder'];
        yield 'extra secret too short' => [['extraSecrets' => [self::extra('short')]], 'at least 32'];
        yield 'required extra secret empty' => [['extraSecrets' => [self::extra('', required: true)]], 'MERCURE_JWT_SECRET must be set'];
        yield 'redis dsn without password' => [['dsnCredentials' => [self::dsn('redis://redis:6379')]], 'must carry a password'];
        yield 'amqp default credentials' => [['dsnCredentials' => [self::dsn('amqp://guest:guest@rabbit:5672/%2f')]], 'default credentials (guest)'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unsafe')]
    public function testUnsafeValuesThrow(array $overrides, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->guard($overrides)->assertSafe();
    }

    public function testExtraSecretsAndDsnsPassWhenSafeOrNotApplicable(): void
    {
        $this->guard([
            'extraSecrets'   => [self::extra(''), self::extra(str_repeat('s', 32))],
            'dsnCredentials' => [
                self::dsn(''),
                self::dsn('doctrine://default'),
                self::dsn('redis://:secret@redis:6379'),
                self::dsn('redis://redis:6379?dbindex=1&auth=secret'),
                self::dsn('redis://redis:6379', fallback: 'from-redis-password'),
                self::dsn('amqp://app:unique-pass@rabbit:5672/%2f'),
            ],
        ])->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testHashShapeCheckCanBeDisabled(): void
    {
        $this->guard(['panelPasswordHash' => 'legacy-custom-hash', 'checkPasswordHashShape' => false])->assertSafe();
        $this->addToAssertionCount(1);
    }

    /**
     * @return array{label: string, value: string|null, min_length: int, forbidden: list<string>, required: bool}
     */
    private static function extra(string $value, bool $required = false): array
    {
        return ['label' => 'MERCURE_JWT_SECRET', 'value' => $value, 'min_length' => 32, 'forbidden' => ['!ChangeMe!'], 'required' => $required];
    }

    /**
     * @return array{label: string, value: string|null, schemes: list<string>, forbidden_credentials: list<string>, password_fallback: string|null}
     */
    private static function dsn(string $value, ?string $fallback = null): array
    {
        return ['label' => 'MESSENGER_TRANSPORT_DSN', 'value' => $value, 'schemes' => ['redis', 'rediss', 'amqp', 'amqps'], 'forbidden_credentials' => ['guest:guest'], 'password_fallback' => $fallback];
    }

    public function testDisabledChecksAreIgnored(): void
    {
        $this->guard([
            'checkSetupToken'    => false,
            'setupToken'         => null,
            'checkPanelPassword' => false,
            'panelPasswordHash'  => null,
            'checkAppSecret'     => false,
            'appSecret'          => null,
        ])->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testRequestListenerChecksMainRequestsOnly(): void
    {
        $guard  = $this->guard(['setupToken' => '']);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $guard->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));

        $this->expectException(RuntimeException::class);
        $guard->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testConsoleSkipsConfiguredCommands(): void
    {
        $guard = $this->guard(['setupToken' => '']);

        $guard->onConsoleCommand(new ConsoleCommandEvent(new Command('cache:clear'), new ArrayInput([]), new NullOutput()));

        $this->expectException(RuntimeException::class);
        $guard->onConsoleCommand(new ConsoleCommandEvent(new Command('app:anything'), new ArrayInput([]), new NullOutput()));
    }

    public function testConsoleWithoutCommandIsChecked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->guard(['setupToken' => ''])->onConsoleCommand(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()));
    }
}
