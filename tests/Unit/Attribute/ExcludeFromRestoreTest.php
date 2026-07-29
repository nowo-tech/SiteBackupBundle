<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Attribute;

use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use PHPUnit\Framework\TestCase;

final class ExcludeFromRestoreTest extends TestCase
{
    public function testAttributeConstant(): void
    {
        self::assertIsString(ExcludeFromRestore::ROUTE_DEFAULT);
        self::assertNotSame('', ExcludeFromRestore::ROUTE_DEFAULT);
        self::assertStringStartsWith('_site_backup_', ExcludeFromRestore::ROUTE_DEFAULT);
    }
}
