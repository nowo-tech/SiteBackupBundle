<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Keeps the port aliases valid for Symfony's `CheckAliasValidityPass` (`lint:container`), 1.35.2+.
 *
 * The extension aliases both the deprecated port and its 1.35 replacement to the configured service
 * (`security.access_gate` wrapper, `security.actor_resolver`, `setup.admin_provisioner`). A host service may implement
 * only one of them, so each alias whose interface the service class does not implement is removed: a host on the new
 * port only gets no deprecated alias, a host on the deprecated port only gets no new-port alias. The bundle's own
 * services are wired with a direct reference to the configured service, never through these aliases.
 *
 * A configured service that implements neither port is rejected with a clear message. A service whose class cannot be
 * known at this point (factory, unresolvable class) is left untouched, as `CheckAliasValidityPass` itself does.
 */
final class PortAliasValidityPass implements CompilerPassInterface
{
    /** @var array<class-string, class-string> deprecated port => replacement */
    private const PORTS = [
        SiteBackupAccessGateInterface::class => PanelAccessGateInterface::class,
        ActorResolverInterface::class        => PanelActorResolverInterface::class,
        AdminUserProvisionerInterface::class => AdminAccountProvisionerInterface::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(SiteBackupExtension::ACCESS_GATE_ID)) {
            $inner = $container->getDefinition(SiteBackupExtension::ACCESS_GATE_ID)->getArgument('$inner');
            if ($inner instanceof Reference) {
                $this->assertImplementsAPort($container, (string) $inner, SiteBackupAccessGateInterface::class, PanelAccessGateInterface::class);
            }
        }

        foreach (self::PORTS as $legacy => $replacement) {
            foreach ([$legacy, $replacement] as $port) {
                if (!$container->hasAlias($port)) {
                    continue;
                }

                $id = (string) $container->getAlias($port);
                $this->assertImplementsAPort($container, $id, $legacy, $replacement);

                $class = $this->serviceClass($container, $id);
                if ($class !== null && !is_a($class, $port, true)) {
                    $container->removeAlias($port);
                }
            }
        }
    }

    /**
     * @param class-string $legacy
     * @param class-string $replacement
     */
    private function assertImplementsAPort(ContainerBuilder $container, string $id, string $legacy, string $replacement): void
    {
        $class = $this->serviceClass($container, $id);
        if ($class === null || is_a($class, $legacy, true) || is_a($class, $replacement, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf('SiteBackup: service "%s" (%s) must implement "%s" (or the deprecated "%s").', $id, $class, $replacement, $legacy));
    }

    /** @return class-string|null null when the class cannot be known before the container is compiled */
    private function serviceClass(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->has($id)) {
            return null;
        }

        $definition = $container->findDefinition($id);
        if ($definition->getFactory() !== null) {
            return null;
        }

        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
