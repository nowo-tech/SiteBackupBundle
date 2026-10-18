<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
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
        self::assertArrayNotHasKey('layout_template', $config['setup']);
        self::assertArrayNotHasKey('layout_template', $config['panel']);
        self::assertNull($config['css_framework']);
        self::assertSame(['enabled' => true, 'path_prefix' => '/_site_backup', 'layout_template' => null, 'guest_layout_template' => null, 'templates' => []], $config['web_ui']);
        self::assertTrue($config['panel']['enabled']);
        self::assertSame('/_site_backup', $config['panel']['path_prefix']);
        self::assertSame(
            ['enabled' => true, 'limit' => 5, 'interval' => '15 minutes', 'policy' => 'fixed_window', 'limiter' => null, 'cache_pool' => null],
            $config['security']['login_throttle'],
        );
        self::assertSame('never', $config['setup']['locale']['in_path']);
        self::assertSame('en', $config['setup']['locale']['default']);
        self::assertSame(['en'], $config['setup']['locale']['enabled']);
        self::assertSame('redirect', $config['setup']['locale']['unlocalized']);
        self::assertFalse($config['setup']['durable_done']['enabled']);
        self::assertSame('/', $config['setup']['durable_done']['redirect_target']);
        self::assertFalse($config['setup']['cold_start']['enabled']);
        self::assertTrue($config['setup']['cold_start']['stop_propagation']);
        self::assertContains('/health/', $config['setup']['cold_start']['safe_path_prefixes']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull($config['templates']['setup_layout']);
        self::assertNull($config['templates']['panel_layout']);
    }

    public function testCssFrameworkAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'css_framework' => 'bootstrap5',
        ]]);
        self::assertSame('bootstrap5', $config['css_framework']);
    }

    public function testPanelChromeDefaultsAndOverrides(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertNull($config['panel']['row_actions_display']);
        self::assertSame(['card' => '', 'table' => '', 'button' => '', 'input' => ''], $config['panel']['extra_classes']);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'panel' => ['row_actions_display' => 'kit', 'extra_classes' => ['table' => 'data-table']],
        ]]);
        self::assertSame('kit', $config['panel']['row_actions_display']);
        self::assertSame('data-table', $config['panel']['extra_classes']['table']);
    }

    /**
     * REQ-UI-003: the 1.x keys are deprecated aliases of the canonical web_ui keys (the canonical key wins).
     */
    public function testLegacyKeysMapToWebUi(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'panel'     => ['enabled' => false, 'path_prefix' => '/ops/backups', 'layout_template' => 'admin.html.twig'],
            'setup'     => ['layout_template' => 'setup_shell.html.twig'],
            'templates' => ['panel_index' => 'my/index.html.twig', 'setup_token' => 'my/token.html.twig', 'restore_page' => 'my/restore.html.twig'],
        ]]);

        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('/ops/backups', $config['web_ui']['path_prefix']);
        self::assertSame('/ops/backups', $config['panel']['path_prefix']);
        self::assertFalse($config['panel']['enabled']);
        self::assertSame('admin.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('setup_shell.html.twig', $config['web_ui']['guest_layout_template']);
        self::assertSame(['restore_page' => 'my/restore.html.twig', 'index' => 'my/index.html.twig', 'setup_token' => 'my/token.html.twig'], $config['web_ui']['templates']);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'panel'     => ['path_prefix' => '/old'],
            'web_ui'    => ['path_prefix' => '/new', 'templates' => ['index' => 'canonical.html.twig']],
            'templates' => ['panel_layout' => '@NowoSiteBackupBundle/panel/layout.html.twig', 'setup_layout' => 'shell.html.twig', 'panel_index' => 'legacy.html.twig'],
        ]]);
        self::assertSame('/new', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template'], 'The removed 1.x bundle layout maps to the UiKit layout.');
        self::assertSame('shell.html.twig', $config['web_ui']['guest_layout_template']);
        self::assertSame(['index' => 'canonical.html.twig'], $config['web_ui']['templates']);
    }

    public function testLegacyKeysAreDeprecated(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $tree);
        $deprecated = [];
        foreach ([['panel', 'enabled'], ['panel', 'path_prefix'], ['panel', 'layout_template'], ['panel', 'row_actions_display'], ['panel', 'extra_classes'], ['setup', 'layout_template'], ['templates', 'panel_layout'], ['templates', 'setup_admin'], ['css_framework']] as $path) {
            $node = $tree;
            foreach ($path as $segment) {
                self::assertInstanceOf(ArrayNode::class, $node);
                $node = $node->getChildren()[$segment];
            }
            if ($node instanceof BaseNode && $node->isDeprecated()) {
                $deprecated[] = implode('.', $path);
            }
        }
        self::assertCount(9, $deprecated);
        $webUi = $tree->getChildren()['web_ui'];
        self::assertInstanceOf(BaseNode::class, $webUi);
        self::assertFalse($webUi->isDeprecated());
    }

    public function testUnknownWebUiTemplatePageRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown nowo_site_backup.web_ui.templates page(s): dashboard');
        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['templates' => ['dashboard' => 'x.html.twig']],
        ]]);
    }

    public function testInvalidRowActionsDisplayRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'panel' => ['row_actions_display' => 'emoji'],
        ]]);
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

    public function testSetupLocaleDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame('never', $config['setup']['locale']['in_path']);
        self::assertSame('en', $config['setup']['locale']['default']);
        self::assertSame(['en'], $config['setup']['locale']['enabled']);
        self::assertSame('redirect', $config['setup']['locale']['unlocalized']);
    }

    public function testSetupLocaleCustomValues(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'locale' => [
                    'in_path'     => 'both',
                    'default'     => 'es',
                    'enabled'     => ['en', 'es', 'fr'],
                    'unlocalized' => 'serve',
                ],
            ],
        ]]);
        self::assertSame('both', $config['setup']['locale']['in_path']);
        self::assertSame('es', $config['setup']['locale']['default']);
        self::assertSame(['en', 'es', 'fr'], $config['setup']['locale']['enabled']);
        self::assertSame('serve', $config['setup']['locale']['unlocalized']);
    }

    public function testSetupLocaleAlwaysMode(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'locale' => [
                    'in_path' => 'always',
                    'default' => 'de',
                    'enabled' => ['de', 'en'],
                ],
            ],
        ]]);
        self::assertSame('always', $config['setup']['locale']['in_path']);
        self::assertSame('de', $config['setup']['locale']['default']);
    }

    public function testSetupLocaleInvalidInPathRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'locale' => ['in_path' => 'invalid'],
            ],
        ]]);
    }

    public function testSetupLocaleInvalidUnlocalizedRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'setup' => [
                'locale' => ['unlocalized' => 'invalid'],
            ],
        ]]);
    }

    public function testLoginThrottleRejectsInvalidInterval(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid login_throttle.interval');
        (new Processor())->processConfiguration(new Configuration(), [['security' => ['login_throttle' => ['interval' => 'not a duration']]]]);
    }

    public function testLoginThrottleRejectsNonPositiveInterval(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['security' => ['login_throttle' => ['interval' => '0 seconds']]]]);
    }

    public function testLoginThrottleRejectsZeroLimit(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['security' => ['login_throttle' => ['limit' => 0]]]]);
    }
}
