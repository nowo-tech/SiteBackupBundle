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
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Reports, once per container build, the host services configured as `security.access_gate`,
 * `security.actor_resolver` or `setup.admin_provisioner` that implement a port deprecated in 1.35.0 (removed in 2.0)
 * without its replacement. No behaviour change.
 */
final class LegacyPortDeprecationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(SiteBackupExtension::ACCESS_GATE_ID)) {
            $inner = $container->getDefinition(SiteBackupExtension::ACCESS_GATE_ID)->getArgument('$inner');
            if ($inner instanceof Reference) {
                $this->report($container, (string) $inner, SiteBackupAccessGateInterface::class, PanelAccessGateInterface::class);
            }
        }

        foreach ([
            ActorResolverInterface::class        => PanelActorResolverInterface::class,
            AdminUserProvisionerInterface::class => AdminAccountProvisionerInterface::class,
        ] as $legacy => $replacement) {
            if ($container->hasAlias($legacy)) {
                $this->report($container, (string) $container->getAlias($legacy), $legacy, $replacement);
            }
        }
    }

    /**
     * @param class-string $legacy
     * @param class-string $replacement
     */
    private function report(ContainerBuilder $container, string $id, string $legacy, string $replacement): void
    {
        if (!$container->has($id)) {
            return;
        }

        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);
        if (!is_string($class) || !class_exists($class) || !is_a($class, $legacy, true) || is_a($class, $replacement, true)) {
            return;
        }

        trigger_deprecation('nowo-tech/site-backup-bundle', '1.35.0', sprintf(
            'Service "%s" (%s) implements "%s", which is removed in 2.0; implement "%s" instead.',
            $id,
            $class,
            $legacy,
            $replacement,
        ));
    }
}
