<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function implode;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Real kernel: setup.locale.enabled given as %app.locales%, %kernel.enabled_locales% or an env var (the host keeps
 * one locale list) must compile, and the setup routes, the exclusion matcher and the time-limit subscriber must see
 * the resolved list.
 */
final class LocaleParameterKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_locale_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideLocaleConfigs(): iterable
    {
        yield 'app parameter' => ['%app.locales%', ['es', 'en', 'fr']];
        yield 'kernel.enabled_locales' => ['%kernel.enabled_locales%', ['de', 'en']];
        yield 'env var (json)' => ['%env(json:' . LocaleEnvVarLoader::JSON . ')%', ['pt', 'en']];
        yield 'env var (csv)' => ['%env(csv:' . LocaleEnvVarLoader::CSV . ')%', ['it', 'en']];
    }

    /**
     * @param list<string> $expectedLocales
     */
    #[DataProvider('provideLocaleConfigs')]
    public function testLocaleListFromParameterReachesRoutesAndGates(string $enabledLocales, array $expectedLocales): void
    {
        $kernel = new LocaleTestKernel($this->baseDir, $enabledLocales);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $route = $router->getRouteCollection()->get('nowo_site_backup_setup');
            self::assertInstanceOf(Route::class, $route);
            self::assertSame('/{_locale}/_setup', $route->getPath());
            self::assertSame(implode('|', $expectedLocales), $route->getRequirement('_locale'));
            self::assertSame('/' . $expectedLocales[0] . '/_setup', $router->generate('nowo_site_backup_setup', ['_locale' => $expectedLocales[0]]));

            $exclusions = $container->get(SiteBackupExclusionMatcher::class);
            self::assertInstanceOf(SiteBackupExclusionMatcher::class, $exclusions);
            $timeLimit = $container->get(LongRequestTimeLimitSubscriber::class);
            self::assertInstanceOf(LongRequestTimeLimitSubscriber::class, $timeLimit);
            foreach ($expectedLocales as $locale) {
                self::assertTrue($exclusions->matches(Request::create('/' . $locale . '/_setup/done')), $locale);
                self::assertTrue($timeLimit->appliesTo('/' . $locale . '/_setup/api/progress'), $locale);
            }
            self::assertFalse($exclusions->matches(Request::create('/zz/_setup')));
            self::assertFalse($timeLimit->appliesTo('/zz/_setup'));
        } finally {
            $kernel->shutdown();
        }
    }
}
