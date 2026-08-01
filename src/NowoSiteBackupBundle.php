<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoSiteBackupBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        // Attribute-only (same pattern as AsSetupTabChecker) — avoids double-tagging
        // if we also autoconfigured SetupNeedDetectorInterface.
        $container->registerAttributeForAutoconfiguration(
            AsSetupNeedDetector::class,
            static function (ChildDefinition $definition, AsSetupNeedDetector $attribute): void {
                $definition->addTag('nowo.site_backup.setup_need_detector', [
                    'priority' => $attribute->priority,
                ]);
            },
        );
        $container->registerAttributeForAutoconfiguration(
            AsSetupTabChecker::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('nowo.site_backup.setup_tab_checker');
            },
        );
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new SiteBackupExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
