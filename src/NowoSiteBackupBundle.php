<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\Attribute\AsSetupStep;
use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\LegacyPortDeprecationPass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\LoginThrottleStoragePass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\SetupReopenDetectorsPass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\SetupStepsPass;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

final class NowoSiteBackupBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new LoginThrottleStoragePass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new SetupReopenDetectorsPass());
        $container->addCompilerPass(new SetupStepsPass());
        $container->addCompilerPass(new LegacyPortDeprecationPass());
        // Attribute-only (same pattern as AsSetupTabChecker) — avoids double-tagging
        // if we also autoconfigured SetupNeedDetectorInterface.
        $container->registerAttributeForAutoconfiguration(
            AsSetupNeedDetector::class,
            static function (ChildDefinition $definition, AsSetupNeedDetector $attribute): void {
                $definition->addTag('nowo.site_backup.setup_need_detector', $attribute->reopen ? [
                    'priority' => $attribute->priority,
                    'reopen'   => true,
                ] : [
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
        $container->registerAttributeForAutoconfiguration(
            AsSetupStep::class,
            static function (ChildDefinition $definition, AsSetupStep $attribute): void {
                $definition->addTag(SetupStepsPass::TAG, ['type' => $attribute->type]);
            },
        );
    }

    /**
     * EO-L1: VarDumper (dump(), the profiler) prints archive passwords as "***".
     */
    public function boot(): void
    {
        parent::boot();
        self::registerVarDumperCasters();
    }

    public static function registerVarDumperCasters(): void
    {
        if (class_exists(AbstractCloner::class)) {
            // @igor-ignore - Process-wide, idempotent caster registration (same value on every boot).
            // @phpstan-ignore-next-line (idempotent, same value for every request)
            AbstractCloner::$defaultCasters[ArchivePassword::class] = [ArchivePassword::class, 'castForVarDumper'];
        }
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new SiteBackupExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
