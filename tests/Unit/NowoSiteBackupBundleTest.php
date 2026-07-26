<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use PHPUnit\Framework\TestCase;
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
}
