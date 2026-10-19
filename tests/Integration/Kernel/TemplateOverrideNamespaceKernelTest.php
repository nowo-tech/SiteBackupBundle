<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

use function dirname;
use function file_get_contents;
use function glob;
use function str_ends_with;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Per-page overrides (web_ui.templates.<page>) extend the bundle original through Symfony's "!" namespace of the
 * bundle, "@!NowoSiteBackup" (the bundle name without "Bundle"); the "@NowoSiteBackupBundle" alias registered by
 * TwigPathsPass has no "!" variant. Regression: the docs showed '@!NowoSiteBackupBundle/…', which does not resolve.
 */
final class TemplateOverrideNamespaceKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_twig_ns_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testDocumentedOverrideNamespaceResolves(): void
    {
        $kernel = new LocaleTestKernel($this->baseDir, '%app.locales%');
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $twig = $container->get('twig');
            self::assertInstanceOf(Environment::class, $twig);
            $loader = $twig->getLoader();

            self::assertTrue($loader->exists('@!NowoSiteBackup/panel/history.html.twig'));
            self::assertTrue($loader->exists('@NowoSiteBackupBundle/panel/history.html.twig'));
            self::assertFalse($loader->exists('@!NowoSiteBackupBundle/panel/history.html.twig'));
            // the documented snippet compiles against the original template
            $twig->createTemplate("{% extends '@!NowoSiteBackup/panel/history.html.twig' %}{% block nowo_ui_list_after %}<p>kept</p>{% endblock %}");
        } finally {
            $kernel->shutdown();
        }

        $root  = dirname(__DIR__, 3);
        $files = [$root . '/README.md', ...(glob($root . '/docs/*.md') ?: []), $root . '/src/DependencyInjection/Configuration.php'];
        foreach ($files as $file) {
            if (str_ends_with($file, '/CHANGELOG.md')) {
                continue; // history: the entry that fixed it names the wrong namespace
            }
            self::assertStringNotContainsString('@!NowoSiteBackupBundle/', (string) file_get_contents($file), $file);
        }
        self::assertStringContainsString("{% extends '@!NowoSiteBackup/panel/history.html.twig' %}", (string) file_get_contents($root . '/docs/USAGE.md'));
    }
}
