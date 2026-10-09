<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\SiteBackupBundle\Command\DatabaseDumpCommand;
use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\ColdStartSchemaGateSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\ProductionSecretsGuardSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupDbDoneRedirectSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\UnlocalizedDefaultLocaleSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Routing\SetupRouteLoader;
use Nowo\SiteBackupBundle\Security\AllowAllSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\ConfigurableSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Setup\ColdStart\MemoizedSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\IncompleteSetupProgressDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;
use Nowo\SiteBackupBundle\Setup\NullDurableSetupDoneStore;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class SiteBackupExtensionTest extends TestCase
{
    public function testLoadDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'enabled'  => true,
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertTrue($container->getParameter('nowo.site_backup.enabled'));
        self::assertTrue($container->hasDefinition(SiteBackupPanelController::class));
        self::assertTrue($container->hasDefinition(SetupWizardController::class));
        self::assertSame(PasswordSiteBackupAccessGate::class, (string) $container->getAlias(SiteBackupAccessGateInterface::class));

        self::assertSame('never', $container->getParameter('nowo.site_backup.setup.locale.in_path'));
        self::assertSame('en', $container->getParameter('nowo.site_backup.setup.locale.default'));
        self::assertSame(['en'], $container->getParameter('nowo.site_backup.setup.locale.enabled'));
        self::assertSame('redirect', $container->getParameter('nowo.site_backup.setup.locale.unlocalized'));
        self::assertTrue($container->hasDefinition(SetupRouteLoader::class));
        self::assertTrue($container->hasDefinition(SetupPathPrefixResolver::class));
        self::assertTrue($container->hasDefinition(SetupUnlocalizedLocaleRedirectController::class));
        self::assertFalse($container->hasDefinition(UnlocalizedDefaultLocaleSubscriber::class));
    }

    public function testLocaleAlwaysAddsExclusionPatternAndLayoutTemplates(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'layout_template' => 'kit/setup_layout.html.twig',
                'locale'          => [
                    'in_path' => 'always',
                    'default' => 'es',
                    'enabled' => ['en', 'es'],
                ],
            ],
            'panel' => [
                'layout_template' => 'kit/panel_layout.html.twig',
            ],
        ]], $container);

        self::assertSame('always', $container->getParameter('nowo.site_backup.setup.locale.in_path'));
        /** @var array<string, string> $templates */
        $templates = $container->getParameter('nowo.site_backup.templates');
        self::assertSame('kit/setup_layout.html.twig', $templates['setup_layout']);
        self::assertSame('kit/panel_layout.html.twig', $templates['panel_layout']);

        $matcher = $container->getDefinition(SiteBackupExclusionMatcher::class);
        /** @var list<string> $patterns */
        $patterns = $matcher->getArgument('$patterns');
        self::assertNotEmpty($patterns);
        self::assertStringContainsString('en|es', $patterns[array_key_last($patterns)]);
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
            'security' => ['access_gate' => 'App\\Security\\CustomGate', 'allow_unauthenticated' => true],
        ]], $container);

        self::assertSame('App\\Security\\CustomGate', (string) $container->getAlias(SiteBackupAccessGateInterface::class));
    }

    public function testGetAlias(): void
    {
        self::assertSame(Configuration::ALIAS, (new SiteBackupExtension())->getAlias());
    }

    public function testLocaleConfigWiredCorrectly(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'locale' => [
                    'in_path'     => 'both',
                    'default'     => 'es',
                    'enabled'     => ['en', 'es'],
                    'unlocalized' => 'serve',
                ],
            ],
        ]], $container);

        self::assertSame('both', $container->getParameter('nowo.site_backup.setup.locale.in_path'));
        self::assertSame('es', $container->getParameter('nowo.site_backup.setup.locale.default'));
        self::assertSame(['en', 'es'], $container->getParameter('nowo.site_backup.setup.locale.enabled'));
        self::assertSame('serve', $container->getParameter('nowo.site_backup.setup.locale.unlocalized'));

        $routeLoader = $container->getDefinition(SetupRouteLoader::class);
        self::assertSame('both', $routeLoader->getArgument('$localeInPath'));
        self::assertSame('es', $routeLoader->getArgument('$defaultLocale'));
        self::assertSame(['en', 'es'], $routeLoader->getArgument('$enabledLocales'));
        self::assertSame('serve', $routeLoader->getArgument('$unlocalizedMode'));

        self::assertTrue($container->hasDefinition(UnlocalizedDefaultLocaleSubscriber::class));
        $localeSubscriber = $container->getDefinition(UnlocalizedDefaultLocaleSubscriber::class);
        self::assertSame('es', $localeSubscriber->getArgument('$defaultLocale'));
        self::assertSame('serve', $localeSubscriber->getArgument('$unlocalizedMode'));
    }

    public function testTabsPreferOverStepsAndWireCheckers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
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

    public function testSetupNeedEvaluatorUsesTaggedDetectors(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([['enabled' => true, 'security' => ['allow_unauthenticated' => true]]], $container);

        $evaluator = $container->getDefinition(SetupNeedEvaluator::class);
        $detectors = $evaluator->getArgument('$detectors');
        self::assertInstanceOf(TaggedIteratorArgument::class, $detectors);
        self::assertSame('nowo.site_backup.setup_need_detector', $detectors->getTag());

        foreach ([
            MarkerFileDetector::class,
            DoctrineConnectDetector::class,
            DoctrineSchemaEmptyDetector::class,
            IncompleteSetupProgressDetector::class,
        ] as $class) {
            $def = $container->getDefinition($class);
            self::assertTrue($def->hasTag('nowo.site_backup.setup_need_detector'), $class);
        }

        self::assertTrue($evaluator->getArgument('$shortCircuitWhenDone'));
        self::assertInstanceOf(Reference::class, $evaluator->getArgument('$markers'));
        self::assertInstanceOf(Reference::class, $evaluator->getArgument('$durableDoneStore'));
        self::assertFalse($evaluator->getArgument('$reopenWhenDetectorRequires'));
        self::assertInstanceOf(Reference::class, $evaluator->getArgument('$reopener'));
        self::assertTrue($container->getDefinition(SetupWizardReopener::class)->isPublic());
    }

    public function testSetupNeedEvaluatorCanReopenWhenDetectorRequires(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'enabled'  => true,
            'security' => ['allow_unauthenticated' => true],
            'setup'    => ['reopen_when_detector_requires' => true],
        ]], $container);

        $evaluator = $container->getDefinition(SetupNeedEvaluator::class);
        self::assertTrue($evaluator->getArgument('$reopenWhenDetectorRequires'));
    }

    public function testSetupNeedEvaluatorCanDisableShortCircuitWhenDone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'enabled'  => true,
            'security' => ['allow_unauthenticated' => true],
            'setup'    => ['short_circuit_when_done' => false],
        ]], $container);

        $evaluator = $container->getDefinition(SetupNeedEvaluator::class);
        self::assertFalse($evaluator->getArgument('$shortCircuitWhenDone'));
    }

    public function testLoadThrowsWhenPanelEnabledWithoutSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        (new SiteBackupExtension())->load([[
            'panel'    => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);
    }

    public function testLoadRegistersAllowAllAccessCheckerWhenUnauthenticatedAllowed(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_site_backup.access_checker.allow_all'));
        self::assertSame(
            AllowAllSiteBackupAccessChecker::class,
            $container->getDefinition('nowo_site_backup.access_checker.allow_all')->getClass(),
        );
        self::assertSame(
            'nowo_site_backup.access_checker.allow_all',
            (string) $container->getAlias(SiteBackupAccessCheckerInterface::class),
        );
        self::assertTrue($container->getDefinition(SiteBackupPanelController::class)->getArgument('$allowUnauthenticated'));
    }

    public function testLoadRegistersDefaultAccessCheckerWhenSecurityBundlePresentViaKernelBundles(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);

        (new SiteBackupExtension())->load([[
            'security' => [
                'allow_unauthenticated' => false,
                'access_roles'          => ['ROLE_ADMIN', 'ROLE_BACKUP'],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_site_backup.access_checker.default'));
        $def = $container->getDefinition('nowo_site_backup.access_checker.default');
        self::assertSame(ConfigurableSiteBackupAccessChecker::class, $def->getClass());
        self::assertSame(['ROLE_ADMIN', 'ROLE_BACKUP'], $def->getArgument('$accessRoles'));
        self::assertFalse($container->getDefinition(SiteBackupPanelController::class)->getArgument('$allowUnauthenticated'));
    }

    public function testLoadUsesCustomAccessCheckerServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setDefinition('app.custom_access_checker', new Definition('stdClass'));

        (new SiteBackupExtension())->load([[
            'security' => [
                'allow_unauthenticated' => false,
                'access_checker'        => 'app.custom_access_checker',
            ],
        ]], $container);

        self::assertSame(
            'app.custom_access_checker',
            (string) $container->getAlias(SiteBackupAccessCheckerInterface::class),
        );
        self::assertFalse($container->hasDefinition('nowo_site_backup.access_checker.default'));
        self::assertFalse($container->hasDefinition('nowo_site_backup.access_checker.allow_all'));
    }

    public function testDurableDoneAndColdStartFlagsRegisterServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'durable_done' => ['enabled' => true, 'redirect_target' => '/home'],
                'cold_start'   => ['enabled' => true],
            ],
        ]], $container);

        self::assertSame(
            NullDurableSetupDoneStore::class,
            (string) $container->getAlias(DurableSetupDoneStoreInterface::class),
        );
        self::assertTrue($container->hasDefinition(SetupDbDoneRedirectSubscriber::class));
        self::assertTrue($container->hasDefinition(ColdStartSchemaGateSubscriber::class));
        self::assertTrue($container->hasDefinition(MysqlSchemaExistenceChecker::class));
    }

    public function testDurableDoneAndColdStartDisabledByDefault(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertFalse($container->hasDefinition(SetupDbDoneRedirectSubscriber::class));
        self::assertFalse($container->hasDefinition(ColdStartSchemaGateSubscriber::class));
        self::assertFalse($container->hasDefinition(MysqlSchemaExistenceChecker::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadWith(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([array_replace_recursive(['security' => ['allow_unauthenticated' => true]], $config)], $container);

        return $container;
    }

    /**
     * Classes excluded from the resource glob are still registered as abstract `container.excluded` definitions.
     */
    private function isRegistered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    private function memoTtl(mixed $definition): ?int
    {
        if (!$definition instanceof Definition) {
            return null;
        }
        self::assertSame(WorkerTtlMemo::class, $definition->getClass());

        return $definition->getArgument('$ttlSeconds');
    }

    public function testWorkerMemoDefaultsAreWired(): void
    {
        $container = $this->loadWith(['setup' => ['cold_start' => ['enabled' => true]]]);

        self::assertSame(3600, $this->memoTtl($container->getDefinition(DoctrineDbalSetupProgressStorage::class)->getArgument('$ddlMemo')));
        self::assertSame(3600, $this->memoTtl($container->getDefinition(DoctrineDbalSetupStepJournal::class)->getArgument('$ddlMemo')));
        self::assertSame(60, $this->memoTtl($container->getDefinition(DoctrineConnectDetector::class)->getArgument('$healthyMemo')));
        self::assertSame(60, $this->memoTtl($container->getDefinition(DoctrineSchemaEmptyDetector::class)->getArgument('$healthyMemo')));
        self::assertSame(MemoizedSchemaExistenceChecker::class, (string) $container->getAlias(SchemaExistenceCheckerInterface::class));
        $memoized = $container->getDefinition(MemoizedSchemaExistenceChecker::class);
        self::assertSame(MysqlSchemaExistenceChecker::class, (string) $memoized->getArgument('$inner'));
        self::assertSame(60, $this->memoTtl($memoized->getArgument('$memo')));
    }

    public function testWorkerMemoZeroDisables(): void
    {
        $container = $this->loadWith(['setup' => [
            'cold_start'  => ['enabled' => true],
            'worker_memo' => ['schema_probe_ttl' => 0, 'progress_ddl_ttl' => 0],
        ]]);

        self::assertNull($container->getDefinition(DoctrineDbalSetupProgressStorage::class)->getArgument('$ddlMemo'));
        self::assertNull($container->getDefinition(DoctrineConnectDetector::class)->getArgument('$healthyMemo'));
        self::assertSame(MysqlSchemaExistenceChecker::class, (string) $container->getAlias(SchemaExistenceCheckerInterface::class));
        self::assertFalse($this->isRegistered($container, MemoizedSchemaExistenceChecker::class));
    }

    public function testTimeLimitSubscriberIsOptIn(): void
    {
        self::assertFalse($this->isRegistered($this->loadWith([]), LongRequestTimeLimitSubscriber::class));

        $container = $this->loadWith([
            'bump_time_limit' => true,
            'process_timeout' => 900,
            'setup'           => ['locale' => ['in_path' => 'both', 'enabled' => ['en', 'es']]],
        ]);
        $def = $container->getDefinition(LongRequestTimeLimitSubscriber::class);
        self::assertSame(['/_site_backup', '/_setup', '/en/_setup', '/es/_setup'], $def->getArgument('$pathPrefixes'));
        self::assertSame(900, $def->getArgument('$seconds'));
        self::assertSame(512, $def->getTag('kernel.event_listener')[0]['priority']);

        $container = $this->loadWith([
            'bump_time_limit'         => true,
            'bump_time_limit_seconds' => 0,
            'panel'                   => ['enabled' => false],
            'setup'                   => ['enabled' => false],
        ]);
        $def = $container->getDefinition(LongRequestTimeLimitSubscriber::class);
        self::assertSame([], $def->getArgument('$pathPrefixes'));
        self::assertSame(0, $def->getArgument('$seconds'));
    }

    public function testSecurityGuardIsOptInAndWired(): void
    {
        self::assertFalse($this->isRegistered($this->loadWith([]), ProductionSecretsGuardSubscriber::class));

        $container = $this->loadWith([
            'security_guard' => ['enabled' => true, 'forbidden_setup_tokens' => ['local']],
            'setup'          => ['setup_token' => '%env(SITE_SETUP_TOKEN)%'],
            'security'       => ['password_hash' => 'h'],
        ]);
        $def = $container->getDefinition(ProductionSecretsGuardSubscriber::class);
        self::assertTrue($def->hasTag('kernel.event_subscriber'));
        self::assertSame('%kernel.environment%', $def->getArgument('$environment'));
        self::assertTrue($def->getArgument('$checkSetupToken'));
        self::assertSame('%env(SITE_SETUP_TOKEN)%', $def->getArgument('$setupToken'));
        self::assertSame(['local'], $def->getArgument('$forbiddenSetupTokens'));
        self::assertTrue($def->getArgument('$checkPanelPassword'));
        self::assertSame('%env(default::APP_SECRET)%', $def->getArgument('$appSecret'));
        self::assertContains('nowo:site-backup:hash-password', $def->getArgument('$skipConsoleCommands'));

        $container = $this->loadWith([
            'security_guard' => ['enabled' => true, 'app_secret' => 'explicit'],
            'security'       => ['access_gate' => 'app.gate'],
            'setup'          => ['enabled' => false],
        ]);
        $def = $container->getDefinition(ProductionSecretsGuardSubscriber::class);
        self::assertFalse($def->getArgument('$checkSetupToken'));
        self::assertFalse($def->getArgument('$checkPanelPassword'));
        self::assertSame('explicit', $def->getArgument('$appSecret'));
    }

    public function testDatabaseDumpCommandWired(): void
    {
        $container = $this->loadWith(['process_timeout' => 120]);
        $def       = $container->getDefinition(DatabaseDumpCommand::class);
        self::assertSame('%env(default::DATABASE_URL)%', $def->getArgument('$databaseUrl'));
        self::assertSame(120, $def->getArgument('$timeoutSeconds'));
    }
}
