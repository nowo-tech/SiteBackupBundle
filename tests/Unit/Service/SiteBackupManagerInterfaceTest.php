<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Service;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function class_implements;

final class SiteBackupManagerInterfaceTest extends TestCase
{
    public function testTheManagerImplementsTheInterfaceAndStaysFinal(): void
    {
        self::assertContains(SiteBackupManagerInterface::class, class_implements(SiteBackupManager::class));
        self::assertTrue((new ReflectionClass(SiteBackupManager::class))->isFinal());
    }

    public function testEveryPublicManagerMethodIsOnTheInterface(): void
    {
        $interface = new ReflectionClass(SiteBackupManagerInterface::class);
        foreach ((new ReflectionClass(SiteBackupManager::class))->getMethods() as $method) {
            if (!$method->isPublic() || $method->isConstructor()) {
                continue;
            }
            self::assertTrue($interface->hasMethod($method->getName()), $method->getName());
        }
    }
}
