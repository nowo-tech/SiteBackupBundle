<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SiteBackupExtensionTest extends TestCase
{
    public function testLoadDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([['enabled' => true]], $container);

        self::assertTrue($container->getParameter('nowo.site_backup.enabled'));
        self::assertTrue($container->hasDefinition(SiteBackupPanelController::class));
        self::assertTrue($container->hasDefinition(SetupWizardController::class));
        self::assertSame(PasswordSiteBackupAccessGate::class, (string) $container->getAlias(SiteBackupAccessGateInterface::class));
    }

    public function testDisabledPanelAndSetup(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'panel' => ['enabled' => false],
            'setup' => ['enabled' => false],
        ]], $container);

        self::assertFalse($container->hasDefinition(SiteBackupPanelController::class));
        self::assertFalse($container->hasDefinition(SetupWizardController::class));
    }

    public function testCustomAccessGate(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['access_gate' => 'App\\Security\\CustomGate'],
        ]], $container);

        self::assertSame('App\\Security\\CustomGate', (string) $container->getAlias(SiteBackupAccessGateInterface::class));
    }

    public function testGetAlias(): void
    {
        self::assertSame(Configuration::ALIAS, (new SiteBackupExtension())->getAlias());
    }

    public function testTabsPreferOverStepsAndWireCheckers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'setup' => [
                'advance_mode' => 'manual',
                'profiles'     => [
                    'with_tabs' => [
                        'advance_mode' => 'automatic',
                        'steps'        => [
                            ['type' => 'marker'],
                        ],
                        'tabs' => [
                            [
                                'type'     => 'custom',
                                'id'       => 'menus',
                                'checker'  => 'App\\MenusChecker',
                                'template' => '@App/menus.twig',
                                'runner'   => [
                                    'type'    => null,
                                    'command' => 'ignored',
                                ],
                            ],
                            [
                                'type'   => 'custom',
                                'id'     => 'sync',
                                'runner' => [
                                    'type'    => 'console',
                                    'command' => 'app:sync',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]], $container);

        $orch     = $container->getDefinition(SetupOrchestrator::class);
        $profiles = $orch->getArgument('$profiles');
        self::assertArrayHasKey('with_tabs', $profiles);
        self::assertSame('automatic', $profiles['with_tabs']['advance_mode']);
        self::assertCount(2, $profiles['with_tabs']['steps']);
        self::assertArrayNotHasKey('runner', $profiles['with_tabs']['steps'][0]);
        self::assertSame('console', $profiles['with_tabs']['steps'][1]['runner']['type']);
        self::assertSame('manual', $orch->getArgument('$defaultAdvanceMode'));
        self::assertTrue($container->hasDefinition(SetupTabCheckerLocator::class));
    }
}
