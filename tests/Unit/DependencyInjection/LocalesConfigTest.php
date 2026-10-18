<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Routing\SetupLocales;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Routing\SetupRouteLoader;
use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function array_replace_recursive;
use function sys_get_temp_dir;

/**
 * setup.locale.enabled as a literal list, a parameter or an env placeholder (direct load() callers), and the
 * cold_start.mysql_port env placeholder.
 */
final class LocalesConfigTest extends TestCase
{
    public function testParameterStringIsResolvedInLoad(): void
    {
        $container = $this->container();
        $container->setParameter('app.locales', ['es', 'en', 'es']);
        $this->load($container, ['bump_time_limit' => true, 'setup' => ['enabled' => true, 'locale' => ['in_path' => 'always', 'enabled' => '%app.locales%']]]);

        self::assertSame(['es', 'en'], $container->getParameter('nowo.site_backup.setup.locale.enabled'));
        self::assertSame(['es', 'en'], $container->getDefinition(SetupRouteLoader::class)->getArgument('$enabledLocales'));
        self::assertSame(['/_site_backup', '/_setup', '/es/_setup', '/en/_setup'], $container->getDefinition(LongRequestTimeLimitSubscriber::class)->getArgument('$pathPrefixes'));
        self::assertContains('#^/(es|en)/_setup(/|$|\?)#', $container->getDefinition(SiteBackupExclusionMatcher::class)->getArgument('$patterns'));
    }

    public function testEnvPlaceholderIsResolvedAtRuntimeByFactories(): void
    {
        $container = $this->container();
        $this->load($container, ['bump_time_limit' => true, 'setup' => ['enabled' => true, 'locale' => ['in_path' => 'always', 'enabled' => '%env(json:APP_LOCALES)%']]]);

        self::assertSame('%env(json:APP_LOCALES)%', $container->resolveEnvPlaceholders($container->getParameter('nowo.site_backup.setup.locale.enabled')));
        foreach ([SetupRouteLoader::class, SetupPathPrefixResolver::class] as $serviceId) {
            $argument = $container->getDefinition($serviceId)->getArgument('$enabledLocales');
            self::assertInstanceOf(Definition::class, $argument, $serviceId);
            self::assertSame([SetupLocales::class, 'normalize'], $argument->getFactory());
        }

        $prefixes = $container->getDefinition(LongRequestTimeLimitSubscriber::class)->getArgument('$pathPrefixes');
        self::assertInstanceOf(Definition::class, $prefixes);
        self::assertSame([SetupLocales::class, 'localizedPrefixes'], $prefixes->getFactory());
        self::assertSame(['/_site_backup', '/_setup'], $prefixes->getArgument(0));

        $patterns = $container->getDefinition(SiteBackupExclusionMatcher::class)->getArgument('$patterns');
        self::assertInstanceOf(Definition::class, $patterns);
        self::assertSame([SetupLocales::class, 'withLocalizedSetupPattern'], $patterns->getFactory());
        self::assertSame('/_setup', $patterns->getArgument(2));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidLocales(): iterable
    {
        yield 'nested list' => [['en', ['es']]];
        yield 'integer' => [42];
    }

    #[DataProvider('provideInvalidLocales')]
    public function testInvalidLocalesAreRejected(mixed $locales): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid nowo_site_backup.setup.locale.enabled');

        $this->load($this->container(), ['setup' => ['locale' => ['enabled' => $locales]]]);
    }

    public function testColdStartMysqlPortEnvPlaceholderIsKept(): void
    {
        // Through MergeExtensionConfigurationPass, like a kernel: the env placeholder then satisfies the integer node.
        $container = $this->container();
        $container->registerExtension(new SiteBackupExtension());
        $container->loadFromExtension('nowo_site_backup', [
            'security' => ['allow_unauthenticated' => true],
            'setup'    => ['cold_start' => ['enabled' => true, 'mysql_port' => '%env(int:DB_PORT)%']],
        ]);
        (new MergeExtensionConfigurationPass())->process($container);

        $port = $container->getDefinition(MysqlSchemaExistenceChecker::class)->getArgument('$port');
        self::assertSame('%env(int:DB_PORT)%', $container->resolveEnvPlaceholders($port));
    }

    public function testPrependIgnoresParameterStringsOutsideTheKeysItReads(): void
    {
        $container = $this->container();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_ui_kit';
            }
        });
        $container->registerExtension($extension = new SiteBackupExtension());
        $container->loadFromExtension('nowo_site_backup', [
            'css_framework'   => 'tabler',
            'process_timeout' => '%app.process_timeout%',
            'setup'           => ['locale' => ['enabled' => '%kernel.enabled_locales%']],
        ]);

        $extension->prepend($container);

        self::assertSame([['css_framework' => 'tabler', 'icon_set' => 'tabler-icons']], $container->getExtensionConfig('nowo_ui_kit'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(ContainerBuilder $container, array $config): void
    {
        (new SiteBackupExtension())->load([array_replace_recursive(['security' => ['allow_unauthenticated' => true]], $config)], $container);
    }
}
