<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Twig;

use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use Nowo\SiteBackupBundle\Twig\SiteBackupExtension;
use PHPUnit\Framework\TestCase;

final class SiteBackupExtensionTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testFunctionsDelegateToManager(): void
    {
        $extension = new SiteBackupExtension($this->createManager());
        self::assertCount(2, $extension->getFunctions());
        self::assertFalse($extension->isRestoring());
        self::assertSame(0.0, $extension->progress()->getPercent());
    }
}
