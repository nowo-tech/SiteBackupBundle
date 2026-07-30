<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        self::assertArrayHasKey('full_database', $config['setup']['profiles']);
        $freshTypes = array_column($config['setup']['profiles']['fresh_install']['steps'], 'type');
        self::assertContains('bootstrap_mode', $freshTypes);
        self::assertContains('sql_file', $freshTypes);
        self::assertFalse($config['setup']['require_done_marker']);
        self::assertSame('filesystem', $config['setup']['progress_storage']);
        self::assertTrue($config['setup']['detectors']['incomplete_progress']);
        self::assertSame('automatic', $config['setup']['advance_mode']);
        self::assertNull($config['setup']['layout_template']);
        self::assertNull($config['panel']['layout_template']);
        self::assertSame('custom', $config['css_framework']);
        self::assertSame(
            '@NowoSiteBackupBundle/setup/layout.html.twig',
            $config['templates']['setup_layout'],
        );
        self::assertSame(
            '@NowoSiteBackupBundle/panel/layout.html.twig',
            $config['templates']['panel_layout'],
        );
    }

    public function testCssFrameworkAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'css_framework' => 'bootstrap5',
        ]]);
        self::assertSame('bootstrap5', $config['css_framework']);
    }

    public function testInvalidCssFrameworkRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'css_framework' => 'material',
        ]]);
    }

    public function testTabsAndAdvanceMode(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'advance_mode' => 'manual',
                'profiles'     => [
                    'demo' => [
                        'advance_mode' => 'automatic',
                        'tabs'         => [
                            [
                                'type'    => 'custom',
                                'id'      => 'menus',
                                'label'   => 'setup.tab.custom',
                                'checker' => 'App\\MenusChecker',
                                'runner'  => [
                                    'type'    => 'console',
                                    'command' => 'app:menus:sync',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertSame('manual', $config['setup']['advance_mode']);
        self::assertSame('automatic', $config['setup']['profiles']['demo']['advance_mode']);
        self::assertCount(1, $config['setup']['profiles']['demo']['tabs']);
        self::assertSame('App\\MenusChecker', $config['setup']['profiles']['demo']['tabs'][0]['checker']);
        self::assertSame('console', $config['setup']['profiles']['demo']['tabs'][0]['runner']['type']);
    }

    public function testSetupLayoutTemplate(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'layout_template' => 'kit/site_backup_setup_layout.html.twig',
            ],
        ]]);
        self::assertSame('kit/site_backup_setup_layout.html.twig', $config['setup']['layout_template']);
    }
}
