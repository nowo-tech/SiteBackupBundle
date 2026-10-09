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
            'panelPasswordHash'       => '$2y$12$realhash',
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
