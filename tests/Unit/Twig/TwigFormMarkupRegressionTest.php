<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function file_get_contents;
use function sprintf;
use function str_contains;

final class TwigFormMarkupRegressionTest extends TestCase
{
    public function testPanelAndSetupTemplatesDoNotUseRawFormTags(): void
    {
        $roots = [
            dirname(__DIR__, 3) . '/src/Resources/views/panel',
            dirname(__DIR__, 3) . '/src/Resources/views/setup',
        ];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_contains($file->getFilename(), '.twig')) {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression(
                    '/<form[\s>]/i',
                    $content,
                    sprintf('Template still contains a raw <form tag: %s', $file->getPathname()),
                );
            }
        }
    }
}
