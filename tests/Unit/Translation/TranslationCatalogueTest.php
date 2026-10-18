<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Translation;

use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function preg_match_all;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * v1.18 (spec 006): every literal key the templates / controllers translate in domain NowoSiteBackupBundle
 * exists in all shipped locales, and every locale has the same (deep) key set.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];

    public function testAllLocalesShareTheSameDeepKeys(): void
    {
        $reference = array_keys($this->catalogue('en'));
        self::assertGreaterThan(100, count($reference));

        foreach (self::LOCALES as $locale) {
            $keys = array_keys($this->catalogue($locale));
            self::assertSame([], array_diff($reference, $keys), sprintf('%s is missing keys', $locale));
            self::assertSame([], array_diff($keys, $reference), sprintf('%s has extra keys', $locale));
        }
    }

    public function testEveryKeyUsedByTemplatesAndControllersExists(): void
    {
        $used = [];
        $root = dirname(__DIR__, 3) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || (!str_ends_with($path, '.twig') && !str_ends_with($path, '.php'))) {
                continue;
            }
            $content = (string) file_get_contents($path);
            // Twig: 'panel.x.y'|trans(…, 'NowoSiteBackupBundle')  — PHP: message('panel.x.y', …) / trans('panel.x.y', …)
            preg_match_all("/'((?:panel|setup|restore)\\.[a-z_]+\\.[a-z_.]+)'\\s*\\|\\s*trans/", $content, $twig);
            preg_match_all("/(?:message|trans|label)\\('((?:panel|setup|restore)\\.[a-z_]+\\.[a-z_.]+)'/", $content, $php);
            // v1.21+: view-model labels (SiteBackupView::label / new Label) and form type options.
            preg_match_all("/(?:Label\\(|'(?:label|help|placeholder)'\\s*=>\\s*)'((?:panel|setup|restore)\\.[a-z_]+\\.[a-z_.]+)'/", $content, $views);
            foreach ([...$twig[1], ...$php[1], ...$views[1]] as $key) {
                $used[$key] = $path;
            }
        }

        self::assertArrayHasKey('panel.login.submit', $used);
        self::assertArrayHasKey('panel.flash.created', $used);
        self::assertArrayHasKey('setup.error.invalid_csrf', $used);
        self::assertArrayHasKey('panel.confirm.restore_expected', $used);
        self::assertArrayHasKey('setup.token.field_placeholder', $used);

        foreach (self::LOCALES as $locale) {
            $catalogue = $this->catalogue($locale);
            $missing   = [];
            foreach ($used as $key => $path) {
                if (!isset($catalogue[$key])) {
                    $missing[] = $key . ' (' . $path . ')';
                }
            }
            self::assertSame([], $missing, sprintf("%s misses:\n%s", $locale, implode("\n", $missing)));
        }
    }

    /**
     * v1.19: restore progress messages are translation ids, translated (not English copies) in every locale.
     */
    public function testRestoreProgressMessagesAreTranslatedInEveryLocale(): void
    {
        $keys = [];
        foreach ((new ReflectionClass(RestoreOrchestrator::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'MESSAGE_')) {
                $keys[] = (string) $constant->getValue();
            }
        }
        self::assertCount(8, $keys);

        $english = $this->catalogue('en');
        foreach (self::LOCALES as $locale) {
            $catalogue = $this->catalogue($locale);
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalogue, sprintf('%s misses %s', $locale, $key));
                self::assertNotSame($key, $catalogue[$key]);
                if ($locale !== 'en') {
                    self::assertNotSame($english[$key], $catalogue[$key], sprintf('%s.%s is an English copy', $locale, $key));
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 3) . '/src/Resources/translations/NowoSiteBackupBundle.' . $locale . '.yaml');
        self::assertIsArray($parsed);

        return $this->flatten($parsed);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }
            $flat[$prefix . $key] = (string) $value;
        }

        return $flat;
    }
}
