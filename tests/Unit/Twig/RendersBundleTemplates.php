<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Twig\SiteBackupExtension;
use ReflectionClass;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

use function dirname;
use function glob;
use function preg_match;

/**
 * Real Twig + Symfony Form/Translation bridge over the bundle templates (functional render tests, v1.18+).
 */
trait RendersBundleTemplates
{
    private function bundleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function createTranslator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (glob($this->bundleRoot() . '/src/Resources/translations/NowoSiteBackupBundle.*.yaml') ?: [] as $file) {
            if (preg_match('/NowoSiteBackupBundle\.([a-z]{2})\.yaml$/', $file, $m) === 1) {
                $translator->addResource('yaml', $file, $m[1], 'NowoSiteBackupBundle');
            }
        }

        return $translator;
    }

    /**
     * @param array<string, string> $overrides extra templates (name => source), resolved before the bundle paths
     * @param array<string, mixed> $panelClasses
     */
    private function createTwig(
        SiteBackupManager $manager,
        string $locale = 'en',
        array $overrides = [],
        string $cssFramework = 'custom',
        string $rowActionsDisplay = 'text',
        array $panelClasses = [],
        ?Request $request = null,
    ): Environment {
        $filesystem = new FilesystemLoader();
        $filesystem->addPath($this->bundleRoot() . '/src/Resources/views', 'NowoSiteBackupBundle');
        $filesystem->addPath($this->bundleRoot() . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');
        $filesystem->addPath(dirname((string) (new ReflectionClass(AppVariable::class))->getFileName()) . '/Resources/views/Form');

        $twig = new Environment(new ChainLoader([new ArrayLoader($overrides), $filesystem]), ['cache' => false]);
        $twig->addExtension(new TranslationExtension($this->createTranslator($locale)));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new SiteBackupExtension(
            $manager,
            cssFramework: $cssFramework,
            rowActionsDisplay: $rowActionsDisplay,
            panelClasses: $panelClasses,
        ));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path, ?string $package = null): string => '/bundles/' . $path));

        $request ??= Request::create('/');
        $request->setLocale($locale);
        $twig->addGlobal('app', (object) ['request' => $request]);

        return $twig;
    }
}
