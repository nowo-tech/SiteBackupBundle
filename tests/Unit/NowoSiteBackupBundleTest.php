<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoSiteBackupBundleTest extends TestCase
{
    public function testBundleRegistersExtensionAndTwigPass(): void
    {
        $bundle = new NowoSiteBackupBundle();
        self::assertInstanceOf(SiteBackupExtension::class, $bundle->getContainerExtension());

        $container = new ContainerBuilder();
        $bundle->build($container);
        $passes = $container->getCompilerPassConfig()->getPasses();
        $found  = false;
        foreach ($passes as $pass) {
            if ($pass instanceof TwigPathsPass) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testBundleAutoconfiguresAsSetupNeedDetectorAttribute(): void
    {
        $container = new ContainerBuilder();
        (new NowoSiteBackupBundle())->build($container);

        $definition = new ChildDefinition('abstract');
        $callbacks  = $container->getAttributeAutoconfigurators()[AsSetupNeedDetector::class] ?? [];
        self::assertNotEmpty($callbacks);
        $callbacks[0]($definition, new AsSetupNeedDetector(priority: 50), new ReflectionClass(AsSetupNeedDetector::class));
        self::assertTrue($definition->hasTag('nowo.site_backup.setup_need_detector'));
        $tags = $definition->getTag('nowo.site_backup.setup_need_detector');
        self::assertSame(50, $tags[0]['priority'] ?? null);
    }
}
