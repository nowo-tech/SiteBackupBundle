<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

final class TwigPathsPassTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-twig-pass-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/templates/bundles/NowoSiteBackupBundle');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);
    }

    public function testAddsBundleAndOverridePaths(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $loader = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.native_filesystem', $loader);

        (new TwigPathsPass())->process($container);

        $calls = $loader->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('prependPath', $calls[0][0]);
        self::assertSame('addPath', $calls[1][0]);
    }

    public function testNoOpWithoutLoader(): void
    {
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);
        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testResolvesAliasChain(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $loader = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);
        self::assertNotEmpty($loader->getMethodCalls());
    }
}
