<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection\Compiler;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Attribute\AsSetupStep;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\LegacyPortDeprecationPass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\SetupStepsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Support\Ports\HostSeedStep;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyActorResolverFixture;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyProvisionerFixture;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostActorResolver;
use Nowo\SiteBackupBundle\Tests\Support\Ports\TypedHostProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;

use const E_USER_DEPRECATED;
use const PHP_BINARY;

final class ArchitecturePortsPassTest extends TestCase
{
    public function testHostStepsAreHandedToTheFactoryByType(): void
    {
        $container = $this->factoryContainer();
        $container->register('app.seed', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'host_seed']);
        $container->register('app.other', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'other_2']);

        (new SetupStepsPass())->process($container);

        self::assertEquals(
            ['host_seed' => new Reference('app.seed'), 'other_2' => new Reference('app.other')],
            $container->getDefinition(SetupStepFactory::class)->getArgument('$customSteps'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'missing type' => [[], 'needs a "type"'];
        yield 'upper case' => [['type' => 'Seed'], 'needs a "type"'];
        yield 'not a string' => [['type' => 3], 'needs a "type"'];
        foreach (SetupStepsPass::RESERVED_TYPES as $reserved) {
            yield 'reserved ' . $reserved => [['type' => $reserved], 'reserved type'];
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('invalidTags')]
    public function testInvalidOrReservedTypesFailTheBuild(array $attributes, string $message): void
    {
        $container = $this->factoryContainer();
        $container->register('app.seed', HostSeedStep::class)->addTag(SetupStepsPass::TAG, $attributes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new SetupStepsPass())->process($container);
    }

    public function testDuplicateTypeFailsTheBuild(): void
    {
        $container = $this->factoryContainer();
        $container->register('app.a', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'seed']);
        $container->register('app.b', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'seed']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registered twice');
        (new SetupStepsPass())->process($container);
    }

    public function testWithoutFactoryThePassIsANoOp(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.seed', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'admin_user']);
        (new SetupStepsPass())->process($container);

        self::assertFalse($container->hasDefinition(SetupStepFactory::class));
    }

    /**
     * Every reserved type is a real built-in (or `custom`): the reservation list cannot drift from the factory.
     */
    public function testReservedTypesAreTheBuiltIns(): void
    {
        $dir     = sys_get_temp_dir();
        $factory = new SetupStepFactory(new ConsoleProcessRunner($dir, PHP_BINARY, 30), new SetupMarkerManager($dir . '/r', $dir . '/d'), new NullAdminUserProvisioner());
        foreach (SetupStepsPass::RESERVED_TYPES as $type) {
            $config = ['type' => $type, 'command' => 'list', 'commands' => []];
            self::assertNotSame('', $factory->create($config, 0)->getId(), $type);
        }
        self::assertSame('x', (new AsSetupStep('x'))->type);
    }

    /**
     * MJ-S-L2 reverse check: every built-in arm of SetupStepFactory::buildBuiltIn() is reserved.
     */
    public function testEveryBuiltInArmIsReserved(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/Setup/SetupStepFactory.php');
        $start  = strpos($source, 'private function buildBuiltIn(');
        self::assertIsInt($start);
        $body = substr($source, $start, (int) strpos($source, 'default  => throw', $start) - $start);
        preg_match_all("/^\\s+'([a-z_]+)'\\s*=>\\s*new /m", $body, $arms);

        self::assertCount(12, $arms[1]);
        foreach ($arms[1] as $type) {
            self::assertContains($type, SetupStepsPass::RESERVED_TYPES, $type);
        }
        self::assertContains('custom', SetupStepsPass::RESERVED_TYPES);
        self::assertSame(SetupStepFactory::RESERVED_TYPES, SetupStepsPass::RESERVED_TYPES);
    }

    public function testFactoryRefusesAReservedHostKey(): void
    {
        $dir = sys_get_temp_dir();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        new SetupStepFactory(new ConsoleProcessRunner($dir, PHP_BINARY, 30), new SetupMarkerManager($dir . '/r', $dir . '/d'), new NullAdminUserProvisioner(), null, ['admin_user' => new HostSeedStep()]);
    }

    public function testFactoryRefusesAHostTypeUsedTwiceInOneProfile(): void
    {
        $dir     = sys_get_temp_dir();
        $factory = new SetupStepFactory(new ConsoleProcessRunner($dir, PHP_BINARY, 30), new SetupMarkerManager($dir . '/r', $dir . '/d'), new NullAdminUserProvisioner(), null, ['host_seed' => new HostSeedStep()]);
        self::assertCount(2, $factory->createAll([['type' => 'host_seed'], ['type' => 'marker']]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('used twice');
        $factory->createAll([['type' => 'host_seed'], ['type' => 'marker'], ['type' => 'host_seed']]);
    }

    public function testRepeatedHostTypeInAProfileFailsTheBuild(): void
    {
        $container = $this->factoryContainer();
        $container->register(SetupOrchestrator::class)->setArgument('$profiles', [
            'ok'  => ['steps' => [['type' => 'host_seed'], ['type' => 'marker'], 'not-a-step', ['type' => 3]]],
            'bad' => ['steps' => [['type' => 'host_seed'], ['type' => 'host_seed']]],
        ]);
        $container->register('app.seed', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'host_seed']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Setup profile "bad" uses the host step type "host_seed" twice.');
        (new SetupStepsPass())->process($container);
    }

    public function testDistinctHostTypesPerProfilePass(): void
    {
        $container = $this->factoryContainer();
        $container->register(SetupOrchestrator::class)->setArgument('$profiles', [
            'a' => ['steps' => [['type' => 'host_seed'], ['type' => 'marker']]],
            'b' => ['steps' => [['type' => 'host_seed']]],
            'c' => 'broken',
        ]);
        $container->register('app.seed', HostSeedStep::class)->addTag(SetupStepsPass::TAG, ['type' => 'host_seed']);

        (new SetupStepsPass())->process($container);

        self::assertEquals(['host_seed' => new Reference('app.seed')], $container->getDefinition(SetupStepFactory::class)->getArgument('$customSteps'));
    }

    public function testDbalConnectionDefaultsToTheDoctrineDefaultAndIgnoresItsAbsence(): void
    {
        $container = $this->loaded([]);
        foreach ($this->dbalConsumers() as $id => $argument) {
            $reference = $container->getDefinition($id)->getArgument($argument);
            self::assertInstanceOf(Reference::class, $reference, $id);
            self::assertSame('doctrine.dbal.default_connection', (string) $reference, $id);
            self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $reference->getInvalidBehavior(), $id);
        }
    }

    public function testDbalConnectionCanPointAtAnotherConnectionThatMustExist(): void
    {
        $container = $this->loaded(['dbal_connection' => 'doctrine.dbal.ops_connection']);
        foreach ($this->dbalConsumers() as $id => $argument) {
            $reference = $container->getDefinition($id)->getArgument($argument);
            self::assertInstanceOf(Reference::class, $reference, $id);
            self::assertSame('doctrine.dbal.ops_connection', (string) $reference, $id);
            self::assertSame(ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $reference->getInvalidBehavior(), $id);
        }
    }

    public function testNewPortsGetAliasesToTheConfiguredServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true, 'access_gate' => 'app.gate', 'actor_resolver' => 'app.actor'],
            'setup'    => ['admin_provisioner' => 'app.provisioner'],
        ]], $container);

        self::assertSame(SiteBackupExtension::ACCESS_GATE_ID, (string) $container->getAlias(PanelAccessGateInterface::class));
        self::assertSame('app.actor', (string) $container->getAlias(PanelActorResolverInterface::class));
        self::assertSame('app.actor', (string) $container->getAlias(ActorResolverInterface::class));
        self::assertSame('app.provisioner', (string) $container->getAlias(AdminAccountProvisionerInterface::class));
        self::assertSame('app.provisioner', (string) $container->getAlias(AdminUserProvisionerInterface::class));
        self::assertSame('security.token_storage', (string) $container->getDefinition(SiteBackupExtension::ACCESS_GATE_ID)->getArgument('$tokenStorage'));
    }

    public function testLegacyHostServicesAreReported(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('gate.class', LegacyHostAccessGate::class);
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true, 'access_gate' => 'app.gate', 'actor_resolver' => 'app.actor'],
            'setup'    => ['admin_provisioner' => 'app.provisioner'],
        ]], $container);
        $container->register('app.gate', '%gate.class%');
        $container->register('app.actor', LegacyActorResolverFixture::class);
        $container->register('app.provisioner', LegacyProvisionerFixture::class);

        $messages = $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process($container));

        self::assertCount(3, $messages);
        self::assertStringContainsString(PanelAccessGateInterface::class, $messages[0]);
        self::assertStringContainsString(PanelActorResolverInterface::class, $messages[1]);
        self::assertStringContainsString(AdminAccountProvisionerInterface::class, $messages[2]);
        self::assertStringContainsString('removed in 2.0', $messages[2]);
    }

    public function testDefaultsAndNewPortsAreNotReported(): void
    {
        self::assertSame([], $this->deprecations(fn () => (new LegacyPortDeprecationPass())->process($this->loaded([]))));

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true, 'access_gate' => PortHostAccessGate::class, 'actor_resolver' => PortHostActorResolver::class],
            'setup'    => ['admin_provisioner' => 'app.unknown'],
        ]], $container);
        $container->register(PortHostAccessGate::class);
        $container->register(PortHostActorResolver::class);
        $container->register('app.typed', TypedHostProvisioner::class);
        self::assertSame([], $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process($container)));
        self::assertSame([], $this->deprecations(static fn () => (new LegacyPortDeprecationPass())->process(new ContainerBuilder())));
    }

    /**
     * @return array<string, string> service id => argument
     */
    private function dbalConsumers(): array
    {
        return [
            DoctrineConnectDetector::class          => '$connection',
            DoctrineSchemaEmptyDetector::class      => '$connection',
            DoctrineDbalSetupProgressStorage::class => '$connection',
            DoctrineDbalSetupStepJournal::class     => '$connection',
            SetupStepFactory::class                 => '$dbalConnection',
            MysqlSchemaExistenceChecker::class      => '$connection',
        ];
    }

    /**
     * @param array<string, mixed> $setup
     */
    private function loaded(array $setup): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => $setup + ['cold_start' => ['enabled' => true]],
        ]], $container);

        return $container;
    }

    private function factoryContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(SetupStepFactory::class)->setArgument('$customSteps', []);

        return $container;
    }

    /**
     * @return list<string>
     */
    private function deprecations(callable $run): array
    {
        $messages = [];
        // @phpstan-ignore-next-line test-only, restored in the finally below
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
