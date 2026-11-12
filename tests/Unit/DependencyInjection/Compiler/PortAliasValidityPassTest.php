<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\SiteBackupBundle\DependencyInjection\Compiler\PortAliasValidityPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyProvisionerFixture;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostActorResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

final class PortAliasValidityPassTest extends TestCase
{
    public function testRemovesOnlyTheAliasOfThePortTheServiceDoesNotImplement(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.resolver', PortHostActorResolver::class);
        $container->register(LegacyProvisionerFixture::class);
        $container->setAlias(ActorResolverInterface::class, 'app.resolver');
        $container->setAlias(PanelActorResolverInterface::class, 'app.resolver');
        $container->setAlias(AdminUserProvisionerInterface::class, LegacyProvisionerFixture::class);
        $container->setAlias(AdminAccountProvisionerInterface::class, LegacyProvisionerFixture::class);

        (new PortAliasValidityPass())->process($container);

        self::assertFalse($container->hasAlias(ActorResolverInterface::class));
        self::assertTrue($container->hasAlias(PanelActorResolverInterface::class));
        self::assertTrue($container->hasAlias(AdminUserProvisionerInterface::class));
        self::assertFalse($container->hasAlias(AdminAccountProvisionerInterface::class));
    }

    public function testClassFromParameterAndIdWithoutClassAreResolved(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.resolver_class', PortHostActorResolver::class);
        $container->register('app.resolver', '%app.resolver_class%');
        $container->setDefinition(LegacyProvisionerFixture::class, new Definition());
        $container->setAlias(ActorResolverInterface::class, 'app.resolver');
        $container->setAlias(AdminAccountProvisionerInterface::class, LegacyProvisionerFixture::class);

        (new PortAliasValidityPass())->process($container);

        self::assertFalse($container->hasAlias(ActorResolverInterface::class));
        self::assertFalse($container->hasAlias(AdminAccountProvisionerInterface::class));
    }

    public function testUnknownClassFactoryOrMissingServiceIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.factory_resolver', PortHostActorResolver::class)->setFactory([PortHostActorResolver::class, 'create']);
        $container->register('app.virtual', 'App\\Missing\\Resolver');
        $container->setAlias(ActorResolverInterface::class, 'app.factory_resolver');
        $container->setAlias(PanelActorResolverInterface::class, 'app.virtual');
        $container->setAlias(AdminUserProvisionerInterface::class, 'app.not_defined');

        (new PortAliasValidityPass())->process($container);

        self::assertTrue($container->hasAlias(ActorResolverInterface::class));
        self::assertTrue($container->hasAlias(PanelActorResolverInterface::class));
        self::assertTrue($container->hasAlias(AdminUserProvisionerInterface::class));
    }

    public function testGateAliasesOnTheWrapperAreKept(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.gate', LegacyHostAccessGate::class);
        $container->register(SiteBackupExtension::ACCESS_GATE_ID, ThrottledSiteBackupAccessGate::class)
            ->setArgument('$inner', new Reference('app.gate'));
        $container->setAlias(SiteBackupAccessGateInterface::class, SiteBackupExtension::ACCESS_GATE_ID);
        $container->setAlias(PanelAccessGateInterface::class, SiteBackupExtension::ACCESS_GATE_ID);

        (new PortAliasValidityPass())->process($container);

        self::assertTrue($container->hasAlias(SiteBackupAccessGateInterface::class));
        self::assertTrue($container->hasAlias(PanelAccessGateInterface::class));
    }

    public function testInnerGateOnNeitherPortIsRejected(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.gate', stdClass::class);
        $container->register(SiteBackupExtension::ACCESS_GATE_ID, ThrottledSiteBackupAccessGate::class)
            ->setArgument('$inner', new Reference('app.gate'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('service "app.gate" (stdClass) must implement "' . PanelAccessGateInterface::class . '"');

        (new PortAliasValidityPass())->process($container);
    }

    public function testConfiguredServiceOnNeitherPortIsRejected(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.provisioner', stdClass::class);
        $container->setAlias(AdminUserProvisionerInterface::class, 'app.provisioner');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement "' . AdminAccountProvisionerInterface::class . '"');

        (new PortAliasValidityPass())->process($container);
    }
}
