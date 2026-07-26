<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertTrue($config['enabled']);
        self::assertSame('/_site_backup', $config['panel']['path_prefix']);
        self::assertSame(600, $config['process_timeout']);
        self::assertNotEmpty($config['backup']['include_paths']);
        self::assertStringContainsString('restore-progress.json', $config['restore']['progress_file']);
        self::assertTrue($config['setup']['enabled']);
        self::assertSame('/_setup', $config['setup']['path_prefix']);
        self::assertArrayHasKey('fresh_install', $config['setup']['profiles']);
        self::assertArrayHasKey('post_restore', $config['setup']['profiles']);
        self::assertFalse($config['setup']['require_done_marker']);
    }
}
