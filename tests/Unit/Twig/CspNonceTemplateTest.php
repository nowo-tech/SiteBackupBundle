<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use Nowo\SiteBackupBundle\Model\RestoreProgress;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * nowo-tech kit CSP convention: inline <script>/<style> carry request attribute `csp_nonce`,
 * no inline event handlers / style attributes (nonce-based CSP blocks them).
 */
final class CspNonceTemplateTest extends TestCase
{
    private function viewsDir(): string
    {
        return dirname(__DIR__, 3) . '/src/Resources/views';
    }

    public function testInlineTagsCarryNonceAndNoInlineHandlers(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->viewsDir()));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_contains($file->getFilename(), '.twig')) {
                continue;
            }
            $path    = $file->getPathname();
            $content = (string) file_get_contents($path);

            preg_match_all('/<(script|style)\b[^>]*>/i', $content, $tags);
            foreach ($tags[0] as $tag) {
                self::assertStringContainsString('nonce', $tag, sprintf('%s: %s without nonce', $path, $tag));
            }

            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $content, sprintf('%s has an inline on* handler', $path));
            self::assertDoesNotMatchRegularExpression('/\bon(submit|click|change|load)\s*:/i', $content, sprintf('%s passes an on* attr to a form helper', $path));
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*"/i', $content, sprintf('%s has an inline style attribute', $path));
        }
    }

    public function testAllTemplatesStillParse(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->viewsDir(), 'NowoSiteBackupBundle');
        $twig = new Environment($loader);
        $twig->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): string => ''));
        $twig->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (): string => ''));

        $count    = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->viewsDir()));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_contains($file->getFilename(), '.twig')) {
                continue;
            }
            $twig->parse($twig->tokenize(new Source((string) file_get_contents($file->getPathname()), $file->getFilename(), $file->getPathname())));
            ++$count;
        }

        self::assertGreaterThan(10, $count);
    }

    public function testRestorePageRendersNonceFromRequestAttribute(): void
    {
        $request = Request::create('/');
        $request->attributes->set('csp_nonce', 'abc123');

        $html = $this->renderRestorePage(['request' => $request]);
        self::assertSame(2, substr_count($html, 'nonce="abc123"'));

        $html = $this->renderRestorePage(['request' => Request::create('/')]);
        self::assertStringNotContainsString('nonce=', $html);

        $html = $this->renderRestorePage(null);
        self::assertStringNotContainsString('nonce=', $html);
    }

    /**
     * @param array{request: Request}|null $app
     */
    private function renderRestorePage(?array $app): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->viewsDir(), 'NowoSiteBackupBundle');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $id): string => $id));

        $context = [
            'progress'       => new RestoreProgress(),
            'progressUrl'    => '/_site_backup/progress',
            'defaultMessage' => 'restore.page.message',
        ];
        if ($app !== null) {
            $context['app'] = (object) $app;
        }

        return $twig->render('@NowoSiteBackupBundle/restore/page.html.twig', $context);
    }
}
