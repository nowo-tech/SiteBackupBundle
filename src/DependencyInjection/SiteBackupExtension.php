<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection;

use LogicException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Routing\SetupRouteLoader;
use Nowo\SiteBackupBundle\Security\AllowAllSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\ConfigurableSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\IncompleteSetupProgressDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use Nowo\SiteBackupBundle\Setup\Storage\ChainSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;
use Nowo\SiteBackupBundle\Twig\SiteBackupExtension as SiteBackupTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_string;

final class SiteBackupExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependUiKitDefaults($container);
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from
     * root css_framework so kit macros resolve the same stack.
     * Does not override keys the host already set under nowo_ui_kit.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config   = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $defaults = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($config['css_framework'] ?? 'custom');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fw                   = (string) ($defaults['css_framework'] ?? $config['css_framework'] ?? 'custom');
            $defaults['icon_set'] = $fw === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('nowo.site_backup.enabled', $config['enabled']);
        $container->setParameter('nowo.site_backup.default_message', $config['default_message']);
        $container->setParameter('nowo.site_backup.process_timeout', $config['process_timeout']);
        $container->setParameter('nowo.site_backup.css_framework', $config['css_framework']);
        $container->setParameter('nowo.site_backup.backup', $config['backup']);
        $container->setParameter('nowo.site_backup.restore', $config['restore']);
        $container->setParameter('nowo.site_backup.panel.path_prefix', $config['panel']['path_prefix']);
        $container->setParameter('nowo.site_backup.setup.path_prefix', $config['setup']['path_prefix']);

        $locale = $config['setup']['locale'] ?? [];
        $container->setParameter('nowo.site_backup.setup.locale.in_path', $locale['in_path'] ?? 'never');
        $container->setParameter('nowo.site_backup.setup.locale.default', $locale['default'] ?? 'en');
        $container->setParameter('nowo.site_backup.setup.locale.enabled', $locale['enabled'] ?? ['en']);
        $container->setParameter('nowo.site_backup.setup.locale.unlocalized', $locale['unlocalized'] ?? 'redirect');

        $setupLayout = $config['setup']['layout_template'] ?? null;
        if (is_string($setupLayout) && $setupLayout !== '') {
            $config['templates']['setup_layout'] = $setupLayout;
        }

        $panelLayout = $config['panel']['layout_template'] ?? null;
        if (is_string($panelLayout) && $panelLayout !== '') {
            $config['templates']['panel_layout'] = $panelLayout;
        }

        $container->setParameter('nowo.site_backup.templates', $config['templates']);
        $container->setParameter('nowo.site_backup.setup', $config['setup']);
        $container->setParameter('nowo.site_backup.panel', $config['panel']);
        $container->setParameter('nowo.site_backup.security', $config['security']);
        $container->setParameter('nowo.site_backup.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);

        if (
            (bool) $config['panel']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoSiteBackupBundle panel requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $this->configureStorage($container, $config);
        $this->configureArchiver($container, $config);
        $this->configureRestore($container, $config);
        $this->configureExclusions($container, $config);
        $this->configureAccessGate($container, $config['security']);
        $this->registerAccessChecker($container, $config['security']);
        $this->configureManager($container);
        $this->configureSubscriber($container, $config);
        $this->configureTwigGlobals($container, $config);
        $this->configurePanel($container, $config);
        $this->configureSetup($container, $config);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureStorage(ContainerBuilder $container, array $config): void
    {
        $progressFile = is_string($config['restore']['progress_file'] ?? null)
            ? $config['restore']['progress_file']
            : '%kernel.project_dir%/var/site-backup/restore-progress.json';
        $historyFile = is_string($config['storage']['history_file'] ?? null)
            ? $config['storage']['history_file']
            : '%kernel.project_dir%/var/site-backup/history.jsonl';

        $container->getDefinition(FilesystemRestoreProgressStorage::class)
            ->setArgument('$filePath', $progressFile);
        $container->getDefinition(FilesystemBackupHistoryStorage::class)
            ->setArgument('$filePath', $historyFile);

        $container->setAlias(RestoreProgressStorageInterface::class, FilesystemRestoreProgressStorage::class)->setPublic(false);
        $container->setAlias(BackupHistoryStorageInterface::class, FilesystemBackupHistoryStorage::class)->setPublic(false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureArchiver(ContainerBuilder $container, array $config): void
    {
        $backup = $config['backup'];
        $dump   = $backup['database_dump_command'] ?? null;

        $container->getDefinition(BackupArchiver::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$storageDir', $backup['storage_dir'])
            ->setArgument('$includePaths', array_values($backup['include_paths'] ?? []))
            ->setArgument('$excludePatterns', array_values($backup['exclude_patterns'] ?? []))
            ->setArgument('$databaseDumpCommand', is_string($dump) && $dump !== '' ? $dump : null)
            ->setArgument('$processTimeoutSeconds', (int) $config['process_timeout']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureRestore(ContainerBuilder $container, array $config): void
    {
        $setup = $config['setup'];
        $container->getDefinition(RestoreOrchestrator::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$archiver', new Reference(BackupArchiver::class))
            ->setArgument('$progressStorage', new Reference(RestoreProgressStorageInterface::class))
            ->setArgument('$protectedRelativePaths', array_values($config['restore']['protected_paths'] ?? []))
            ->setArgument('$setupMarkers', new Reference(SetupMarkerManager::class))
            ->setArgument('$triggerSetupAfterRestore', (bool) ($setup['trigger_after_restore'] ?? true))
            ->setArgument('$postRestoreSetupProfile', (string) ($setup['post_restore_profile'] ?? 'post_restore'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureExclusions(ContainerBuilder $container, array $config): void
    {
        /** @var array{paths?: list<string>, path_prefixes?: list<string>, routes?: list<string>, patterns?: list<string>, ips?: list<string>} $exclusions */
        $exclusions  = $config['exclusions'];
        $paths       = array_values($exclusions['paths'] ?? []);
        $prefixes    = array_values($exclusions['path_prefixes'] ?? []);
        $panelPrefix = is_string($config['panel']['path_prefix'] ?? null) ? $config['panel']['path_prefix'] : '/_site_backup';
        if ($panelPrefix !== '' && !in_array($panelPrefix, $prefixes, true)) {
            $prefixes[] = $panelPrefix;
        }
        $setupPrefix = is_string($config['setup']['path_prefix'] ?? null) ? $config['setup']['path_prefix'] : '/_setup';
        if ($setupPrefix !== '' && !in_array($setupPrefix, $prefixes, true)) {
            $prefixes[] = $setupPrefix;
        }

        $patterns = array_values($exclusions['patterns'] ?? []);

        $localeConfig  = $config['setup']['locale'] ?? [];
        $localeInPath  = (string) ($localeConfig['in_path'] ?? 'never');
        $localeEnabled = array_values($localeConfig['enabled'] ?? ['en']);
        if ($localeInPath !== 'never' && $localeEnabled !== []) {
            $escapedPrefix = preg_quote($setupPrefix, '#');
            $localePattern = '#^/(' . implode('|', $localeEnabled) . ')' . $escapedPrefix . '(/|$|\\?)#';
            if (!in_array($localePattern, $patterns, true)) {
                $patterns[] = $localePattern;
            }
        }

        $container->getDefinition(SiteBackupExclusionMatcher::class)
            ->setArgument('$paths', $paths)
            ->setArgument('$pathPrefixes', $prefixes)
            ->setArgument('$routes', array_values($exclusions['routes'] ?? []))
            ->setArgument('$patterns', $patterns)
            ->setArgument('$ips', array_values($exclusions['ips'] ?? []));
    }

    /**
     * @param array<string, mixed> $security
     */
    private function configureAccessGate(ContainerBuilder $container, array $security): void
    {
        $custom = $security['access_gate'] ?? null;
        if (is_string($custom) && $custom !== '') {
            $container->setAlias(SiteBackupAccessGateInterface::class, $custom)->setPublic(false);

            return;
        }

        $hash = $security['password_hash'] ?? null;
        $container->getDefinition(PasswordSiteBackupAccessGate::class)
            ->setArgument('$passwordHash', is_string($hash) ? $hash : null)
            ->setArgument('$enabled', (bool) ($security['password_protection'] ?? true));

        $container->setAlias(SiteBackupAccessGateInterface::class, PasswordSiteBackupAccessGate::class)->setPublic(false);
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $accessCheckerId = $security['access_checker'] ?? null;
        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $container->setAlias(SiteBackupAccessCheckerInterface::class, $accessCheckerId)->setPublic(false);

            return;
        }

        if ((bool) ($security['allow_unauthenticated'] ?? false)) {
            $accessCheckerId = 'nowo_site_backup.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllSiteBackupAccessChecker::class));
        } else {
            $accessCheckerId = 'nowo_site_backup.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableSiteBackupAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $security['access_roles']));
        }

        $container->setAlias(SiteBackupAccessCheckerInterface::class, $accessCheckerId)->setPublic(false);
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }

    private function configureManager(ContainerBuilder $container): void
    {
        $container->getDefinition(SiteBackupManager::class)
            ->setArgument('$archiver', new Reference(BackupArchiver::class))
            ->setArgument('$restoreOrchestrator', new Reference(RestoreOrchestrator::class))
            ->setArgument('$historyStorage', new Reference(BackupHistoryStorageInterface::class))
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSubscriber(ContainerBuilder $container, array $config): void
    {
        $definition = $container->getDefinition(RestoreRequestSubscriber::class);
        $definition
            ->setArgument('$enabled', (bool) $config['enabled'])
            ->setArgument('$manager', new Reference(SiteBackupManager::class))
            ->setArgument('$exclusionMatcher', new Reference(SiteBackupExclusionMatcher::class))
            ->setArgument('$twig', new Reference('twig', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$template', $config['templates']['restore_page'])
            ->setArgument('$statusCode', (int) $config['status_code'])
            ->setArgument('$panelPathPrefix', $config['panel']['path_prefix'])
            ->setArgument('$defaultMessage', $config['default_message'] !== '' && $config['default_message'] !== null
                ? (string) $config['default_message']
                : 'restore.page.message')
            ->setArgument('$translator', new Reference('translator', ContainerBuilder::NULL_ON_INVALID_REFERENCE));

        $definition->clearTags();
        $definition->addTag('kernel.event_listener', [
            'event'    => 'kernel.request',
            'method'   => 'onKernelRequest',
            'priority' => (int) $config['subscriber_priority'],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureTwigGlobals(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasDefinition(SiteBackupTwigExtension::class)) {
            return;
        }

        /** @var array<string, string> $templates */
        $templates   = $config['templates'];
        $setupLayout = $templates['setup_layout'] ?? '@NowoSiteBackupBundle/setup/layout.html.twig';
        $panelLayout = $templates['panel_layout'] ?? '@NowoSiteBackupBundle/panel/layout.html.twig';

        $container->getDefinition(SiteBackupTwigExtension::class)
            ->setArgument('$manager', new Reference(SiteBackupManager::class))
            ->setArgument('$setupLayoutTemplate', $setupLayout)
            ->setArgument('$panelLayoutTemplate', $panelLayout)
            ->setArgument('$cssFramework', (string) $config['css_framework']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configurePanel(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasDefinition(SiteBackupPanelController::class)) {
            return;
        }

        if (!(bool) $config['panel']['enabled']) {
            $container->removeDefinition(SiteBackupPanelController::class);

            return;
        }

        $container->getDefinition(SiteBackupPanelController::class)
            ->setArgument('$manager', new Reference(SiteBackupManager::class))
            ->setArgument('$accessGate', new Reference(SiteBackupAccessGateInterface::class))
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$templates', $config['templates'])
            ->setArgument('$pathPrefix', $config['panel']['path_prefix'])
            ->setArgument('$csrfTokenManager', new Reference(CsrfTokenManagerInterface::class, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$accessChecker', new Reference(SiteBackupAccessCheckerInterface::class))
            ->setArgument('$allowUnauthenticated', (bool) $config['security']['allow_unauthenticated'])
            ->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSetup(ContainerBuilder $container, array $config): void
    {
        $setup = $config['setup'];

        $localeConfig   = $setup['locale'] ?? [];
        $localeInPath   = (string) ($localeConfig['in_path'] ?? 'never');
        $localeDefault  = (string) ($localeConfig['default'] ?? 'en');
        $localeEnabled  = array_values($localeConfig['enabled'] ?? ['en']);
        $unlocalizedStr = (string) ($localeConfig['unlocalized'] ?? 'redirect');
        $setupEnabled   = (bool) $setup['enabled'];

        $container->register(SetupPathPrefixResolver::class, SetupPathPrefixResolver::class)
            ->setArgument('$requestStack', new Reference('request_stack'))
            ->setArgument('$basePrefix', $setup['path_prefix'])
            ->setArgument('$localeInPath', $localeInPath)
            ->setArgument('$defaultLocale', $localeDefault)
            ->setArgument('$enabledLocales', $localeEnabled);

        $container->register(SetupRouteLoader::class, SetupRouteLoader::class)
            ->setArgument('$pathPrefix', $setup['path_prefix'])
            ->setArgument('$localeInPath', $localeInPath)
            ->setArgument('$defaultLocale', $localeDefault)
            ->setArgument('$enabledLocales', $localeEnabled)
            ->setArgument('$unlocalizedMode', $unlocalizedStr)
            ->setArgument('$enabled', $setupEnabled)
            ->addTag('routing.loader');

        $container->getDefinition(SetupMarkerManager::class)
            ->setArgument('$requiredFile', $setup['required_marker_file'])
            ->setArgument('$doneFile', $setup['done_marker_file']);

        $dbalRef = new Reference('doctrine.dbal.default_connection', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE);

        $container->getDefinition(FilesystemSetupProgressStorage::class)
            ->setArgument('$filePath', $setup['progress_file']);

        $container->getDefinition(DoctrineDbalSetupStepJournal::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$tableName', (string) ($setup['progress_steps_table'] ?? DoctrineDbalSetupStepJournal::TABLE));

        $stepRowsEnabled = (bool) ($setup['progress_step_rows'] ?? true);
        $progressMode    = (string) ($setup['progress_storage'] ?? 'filesystem');
        if ($progressMode === 'filesystem') {
            $stepRowsEnabled = false;
        }

        $container->getDefinition(DoctrineDbalSetupProgressStorage::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$tableName', (string) ($setup['progress_table'] ?? DoctrineDbalSetupProgressStorage::TABLE))
            ->setArgument('$stepJournal', new Reference(DoctrineDbalSetupStepJournal::class))
            ->setArgument('$stepRowsEnabled', $stepRowsEnabled);

        $container->getDefinition(ChainSetupProgressStorage::class)
            ->setArgument('$filesystem', new Reference(FilesystemSetupProgressStorage::class))
            ->setArgument('$doctrine', new Reference(DoctrineDbalSetupProgressStorage::class));

        $progressStorage = match ((string) ($setup['progress_storage'] ?? 'filesystem')) {
            'doctrine' => DoctrineDbalSetupProgressStorage::class,
            'chain'    => ChainSetupProgressStorage::class,
            default    => FilesystemSetupProgressStorage::class,
        };
        $container->setAlias(SetupProgressStorageInterface::class, $progressStorage)->setPublic(false);

        $container->getDefinition(ConsoleProcessRunner::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$phpBinary', $setup['php_binary'])
            ->setArgument('$timeoutSeconds', (int) $config['process_timeout']);

        $adminProvisioner = $setup['admin_provisioner'] ?? null;
        if (is_string($adminProvisioner) && $adminProvisioner !== '') {
            $container->setAlias(AdminUserProvisionerInterface::class, $adminProvisioner)->setPublic(false);
        } else {
            $container->setAlias(AdminUserProvisionerInterface::class, NullAdminUserProvisioner::class)->setPublic(false);
        }

        $container->getDefinition(MarkerFileDetector::class)
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$requireDoneMarker', (bool) $setup['require_done_marker'])
            ->setArgument('$enabled', (bool) ($setup['detectors']['marker'] ?? true))
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 100]);

        $container->getDefinition(DoctrineConnectDetector::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$enabled', (bool) ($setup['detectors']['doctrine_connect'] ?? false))
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 90]);

        $container->getDefinition(DoctrineSchemaEmptyDetector::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$enabled', (bool) ($setup['detectors']['doctrine_schema_empty'] ?? false))
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 80]);

        $container->getDefinition(IncompleteSetupProgressDetector::class)
            ->setArgument('$progressStorage', new Reference(SetupProgressStorageInterface::class))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$enabled', (bool) ($setup['detectors']['incomplete_progress'] ?? true))
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 70]);

        // Built-ins (above) + host apps via SetupNeedDetectorInterface / #[AsSetupNeedDetector].
        // Distinct from profile tab checkers (SetupTabCheckerInterface / checker: YAML).
        $container->getDefinition(SetupNeedEvaluator::class)
            ->setArgument('$detectors', new TaggedIteratorArgument('nowo.site_backup.setup_need_detector'))
            ->setArgument('$setupEnabled', (bool) $setup['enabled']);

        $container->getDefinition(SetupStepFactory::class)
            ->setArgument('$runner', new Reference(ConsoleProcessRunner::class))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$adminProvisioner', new Reference(AdminUserProvisionerInterface::class))
            ->setArgument('$dbalConnection', $dbalRef)
            ->setArgument('$customSteps', [])
            ->setArgument('$checkerLocator', new Reference(SetupTabCheckerLocator::class));

        /** @var array<string, array{steps?: list<array<string, mixed>>, tabs?: list<array<string, mixed>>, advance_mode?: string|null}> $profiles */
        $profiles      = $setup['profiles'];
        $normalized    = [];
        $checkerRefs   = [];
        $globalAdvance = is_string($setup['advance_mode'] ?? null) ? $setup['advance_mode'] : 'automatic';
        foreach ($profiles as $name => $profile) {
            $rawTabs  = $profile['tabs'] ?? [];
            $rawSteps = $profile['steps'] ?? [];
            $source   = is_array($rawTabs) && $rawTabs !== [] ? $rawTabs : $rawSteps;
            $steps    = [];
            foreach ($source as $step) {
                $clean = array_filter(
                    $step,
                    static fn (mixed $v): bool => $v !== null,
                );
                if (isset($clean['runner']) && is_array($clean['runner'])) {
                    $runner = array_filter(
                        $clean['runner'],
                        static fn (mixed $v): bool => $v !== null,
                    );
                    if (!isset($runner['type']) || !is_string($runner['type']) || $runner['type'] === '') {
                        unset($clean['runner']);
                    } else {
                        $clean['runner'] = $runner;
                    }
                }
                $checker = $clean['checker'] ?? null;
                if (is_string($checker) && $checker !== '') {
                    $checkerRefs[$checker] = new Reference($checker);
                }
                $steps[] = $clean;
            }
            $entry = ['steps' => $steps];
            $mode  = $profile['advance_mode'] ?? null;
            if (is_string($mode) && ($mode === 'automatic' || $mode === 'manual')) {
                $entry['advance_mode'] = $mode;
            }
            $normalized[$name] = $entry;
        }

        $container->getDefinition(SetupTabCheckerLocator::class)
            ->setArgument(
                '$container',
                ServiceLocatorTagPass::register($container, $checkerRefs),
            );

        $container->getDefinition(SetupOrchestrator::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$stepFactory', new Reference(SetupStepFactory::class))
            ->setArgument('$progressStorage', new Reference(SetupProgressStorageInterface::class))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$profiles', $normalized)
            ->setArgument('$defaultProfile', $setup['default_profile'])
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$defaultAdvanceMode', $globalAdvance);

        if ($container->hasDefinition(SetupRequestSubscriber::class)) {
            $sub = $container->getDefinition(SetupRequestSubscriber::class);
            $sub
                ->setArgument('$enabled', $setupEnabled)
                ->setArgument('$needEvaluator', new Reference(SetupNeedEvaluator::class))
                ->setArgument('$backupManager', new Reference(SiteBackupManager::class))
                ->setArgument('$exclusionMatcher', new Reference(SiteBackupExclusionMatcher::class))
                ->setArgument('$setupPathPrefix', $setup['path_prefix'])
                ->setArgument('$panelPathPrefix', $config['panel']['path_prefix'])
                ->setArgument('$enabledLocales', $localeEnabled)
                ->setArgument('$pathPrefixResolver', new Reference(SetupPathPrefixResolver::class));
            $sub->clearTags();
            if ($setupEnabled) {
                $sub->addTag('kernel.event_listener', [
                    'event'    => 'kernel.request',
                    'method'   => 'onKernelRequest',
                    'priority' => (int) $config['subscriber_priority'] - 1,
                ]);
            }
        }

        $container->register(SetupUnlocalizedLocaleRedirectController::class, SetupUnlocalizedLocaleRedirectController::class)
            ->setArgument('$urlGenerator', new Reference('router'))
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        if (!$container->hasDefinition(SetupWizardController::class)) {
            return;
        }

        if (!$setupEnabled) {
            $container->removeDefinition(SetupWizardController::class);

            return;
        }

        $token = $setup['setup_token'] ?? null;
        $container->getDefinition(SetupWizardController::class)
            ->setArgument('$orchestrator', new Reference(SetupOrchestrator::class))
            ->setArgument('$needEvaluator', new Reference(SetupNeedEvaluator::class))
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$templates', $config['templates'])
            ->setArgument('$pathPrefix', $setup['path_prefix'])
            ->setArgument('$brandName', $setup['brand_name'])
            ->setArgument('$setupToken', is_string($token) && $token !== '' ? $token : null)
            ->setArgument('$csrfTokenManager', new Reference(CsrfTokenManagerInterface::class, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$pathPrefixResolver', new Reference(SetupPathPrefixResolver::class))
            ->setPublic(true);
    }
}
