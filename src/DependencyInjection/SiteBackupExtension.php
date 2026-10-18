<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection;

use LogicException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\DatabaseDumpCommand;
use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\LoginThrottleStoragePass;
use Nowo\SiteBackupBundle\EventSubscriber\ColdStartSchemaGateSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\ProductionSecretsGuardSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupDbDoneRedirectSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\UnlocalizedDefaultLocaleSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Routing\PanelRouteLoader;
use Nowo\SiteBackupBundle\Routing\SetupLocales;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Routing\SetupRouteLoader;
use Nowo\SiteBackupBundle\Security\AllowAllSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\ConfigurableSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ColdStart\MemoizedSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\IncompleteSetupProgressDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\NullDurableSetupDoneStore;
use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\CacheDoctrineSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\CacheSetupProgressStorage;
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
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function trigger_deprecation;
use function trim;

final class SiteBackupExtension extends Extension implements PrependExtensionInterface
{
    /** Service the SiteBackupAccessGateInterface alias points to (throttling + session rotation wrapper). */
    public const ACCESS_GATE_ID = 'nowo_site_backup.access_gate';

    /** Built-in panel login RateLimiterFactory (when login_throttle.limiter is null). */
    public const LOGIN_LIMITER_ID = 'nowo_site_backup.login_limiter';

    public const LOGIN_LIMITER_STORAGE_ID = 'nowo_site_backup.login_limiter.storage';

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependUiKitDefaults($container);
    }

    /**
     * 1.x panel.extra_classes key => UiKit theme slots of the NowoSiteBackupBundle exception (classes appended to the
     * framework defaults, as in 1.x).
     *
     * @var array<string, list<string>>
     */
    public const EXTRA_CLASS_SLOTS = [
        'card'   => ['card'],
        'table'  => ['table'],
        'input'  => ['input'],
        'button' => ['btn_primary', 'btn_secondary', 'action'],
    ];

    /**
     * When UiKit is installed: seed nowo_ui_kit.css_framework / icon_set from the deprecated root css_framework when
     * the host set it (and not the UiKit keys), and bridge the deprecated panel.extra_classes into
     * nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes (REQ-UI-003). Prepended: the host's own UiKit config wins.
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

        // Only the keys read here are processed: prepend() sees the raw host config, where any other key may still be
        // a "%parameter%" / env placeholder (e.g. setup.locale.enabled: '%kernel.enabled_locales%', defined by
        // FrameworkExtension::load() after prepend).
        $subsets = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $cfg) {
            $subset = [];
            if (array_key_exists('css_framework', $cfg)) {
                $subset['css_framework'] = $cfg['css_framework'];
            }
            if (is_array($cfg['panel']['extra_classes'] ?? null)) {
                $subset['panel'] = ['extra_classes' => $cfg['panel']['extra_classes']];
            }
            $subsets[] = $subset;
        }
        $config   = $this->processConfiguration(new Configuration(), $subsets);
        $defaults = [];

        $fw = $config['css_framework'] ?? null;
        if (is_string($fw)) {
            $fw = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
            if (!$hostHasCssFramework) {
                $defaults['css_framework'] = $fw;
            }
            if (!$hostHasIconSet) {
                $defaults['icon_set'] = $fw === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
            }
        }

        $classes = self::themeBridge(is_array($config['panel']['extra_classes'] ?? null) ? $config['panel']['extra_classes'] : []);
        if ($classes !== []) {
            trigger_deprecation('nowo-tech/site-backup-bundle', '1.21', 'nowo_site_backup.panel.extra_classes is deprecated, use nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes.');
            $defaults['theme'] = ['bundles' => [SiteBackupView::BUNDLE => ['classes' => $classes]]];
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    /**
     * @param array<array-key, mixed> $extraClasses panel.extra_classes
     *
     * @return array<string, string> UiKit slot => classes
     */
    public static function themeBridge(array $extraClasses): array
    {
        $classes = [];
        foreach (self::EXTRA_CLASS_SLOTS as $key => $slots) {
            $value = $extraClasses[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            foreach ($slots as $slot) {
                $classes[$slot] = trim(($classes[$slot] ?? '') . ' ' . trim($value));
            }
        }

        return $classes;
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

        // setup.locale.enabled may be a parameter (%app.locales%, %kernel.enabled_locales%): the kernel resolves it
        // before load(), a direct load() caller may not. An env var (%env(json:APP_LOCALES)%) stays a placeholder until
        // runtime: the services get it through SetupLocales factories (see enabledLocalesArgument()).
        $enabledLocales = $container->getParameterBag()->resolveValue($config['setup']['locale']['enabled']);
        if (!$this->isRuntimeValue($container, $enabledLocales)) {
            $enabledLocales = SetupLocales::normalize($enabledLocales);
        }
        $config['setup']['locale']['enabled'] = $enabledLocales;

        $locale = $config['setup']['locale'];
        $container->setParameter('nowo.site_backup.setup.locale.in_path', $locale['in_path'] ?? 'never');
        $container->setParameter('nowo.site_backup.setup.locale.default', $locale['default'] ?? 'en');
        $container->setParameter('nowo.site_backup.setup.locale.enabled', $locale['enabled'] ?? ['en']);
        $container->setParameter('nowo.site_backup.setup.locale.unlocalized', $locale['unlocalized'] ?? 'redirect');

        // REQ-UI-003: resolved page templates (web_ui.templates over the defaults; 1.x templates.* mapped by Configuration).
        $config['templates'] = $config['web_ui']['templates'] + SiteBackupView::TEMPLATES;

        $container->setParameter('nowo.site_backup.templates', $config['templates']);
        $container->setParameter('nowo.site_backup.web_ui', $config['web_ui']);
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
        $this->configureView($container, $config);
        $this->configureTwigGlobals($container, $config);
        $this->configurePanel($container, $config);
        $this->configureSetup($container, $config);
        $this->configureTimeLimit($container, $config);
        $this->configureSecurityGuard($container, $config);
        $this->configureDatabaseDump($container, $config);
    }

    /**
     * Separate memo instance per consumer (each one tracks its own table / probe).
     */
    private function workerMemo(int $ttlSeconds): Definition
    {
        return (new Definition(WorkerTtlMemo::class))->setArgument('$ttlSeconds', $ttlSeconds);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureTimeLimit(ContainerBuilder $container, array $config): void
    {
        if (!(bool) ($config['bump_time_limit'] ?? false)) {
            return;
        }

        $prefixes = [];
        if ((bool) $config['panel']['enabled']) {
            $prefixes[] = (string) $config['panel']['path_prefix'];
        }

        $setup = $config['setup'];
        if ((bool) $setup['enabled']) {
            $setupPrefix = (string) $setup['path_prefix'];
            $prefixes[]  = $setupPrefix;
            $localeConf  = $setup['locale'] ?? [];
            if ((string) ($localeConf['in_path'] ?? 'never') !== 'never') {
                $prefixes = $this->localeDerived($container, 'localizedPrefixes', $prefixes, $localeConf['enabled'] ?? [], $setupPrefix);
            }
        }

        $seconds = $config['bump_time_limit_seconds'] ?? null;

        $container->register(LongRequestTimeLimitSubscriber::class, LongRequestTimeLimitSubscriber::class)
            ->setArgument('$pathPrefixes', $prefixes)
            ->setArgument('$seconds', is_int($seconds) ? $seconds : (int) $config['process_timeout'])
            // Before the router (32) and every SiteBackup gate.
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 512]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSecurityGuard(ContainerBuilder $container, array $config): void
    {
        $guard = $config['security_guard'] ?? [];
        if (!(bool) ($guard['enabled'] ?? false)) {
            return;
        }

        $security      = $config['security'];
        $customGate    = is_string($security['access_gate'] ?? null) && $security['access_gate'] !== '';
        $checkPassword = (bool) $guard['require_panel_password']
            && (bool) $config['panel']['enabled']
            && (bool) ($security['password_protection'] ?? true)
            && !$customGate;
        $checkToken = (bool) $guard['require_setup_token'] && (bool) $config['setup']['enabled'];
        $appSecret  = $guard['app_secret'] ?? null;

        $container->register(ProductionSecretsGuardSubscriber::class, ProductionSecretsGuardSubscriber::class)
            ->setArgument('$environment', '%kernel.environment%')
            ->setArgument('$localEnvironments', array_values($guard['local_environments']))
            ->setArgument('$checkSetupToken', $checkToken)
            ->setArgument('$setupToken', $config['setup']['setup_token'] ?? null)
            ->setArgument('$forbiddenSetupTokens', array_values($guard['forbidden_setup_tokens']))
            ->setArgument('$checkPanelPassword', $checkPassword)
            ->setArgument('$panelPasswordHash', $security['password_hash'] ?? null)
            ->setArgument('$forbiddenPasswordHashes', array_values($guard['forbidden_password_hashes']))
            ->setArgument('$checkAppSecret', (bool) $guard['check_app_secret'])
            ->setArgument('$appSecret', is_string($appSecret) && $appSecret !== '' ? $appSecret : '%env(default::APP_SECRET)%')
            ->setArgument('$forbiddenAppSecrets', array_values($guard['forbidden_app_secrets']))
            ->setArgument('$appSecretMinLength', (int) $guard['app_secret_min_length'])
            ->setArgument('$skipConsoleCommands', array_values($guard['skip_console_commands']))
            ->setArgument('$checkPasswordHashShape', (bool) ($guard['check_password_hash_shape'] ?? true))
            ->setArgument('$extraSecrets', array_values($guard['extra_secrets'] ?? []))
            ->setArgument('$dsnCredentials', array_values($guard['dsn_credentials'] ?? []))
            ->addTag('kernel.event_subscriber');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureDatabaseDump(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasDefinition(DatabaseDumpCommand::class)) {
            return;
        }

        $container->getDefinition(DatabaseDumpCommand::class)
            ->setArgument('$databaseUrl', '%env(default::DATABASE_URL)%')
            ->setArgument('$timeoutSeconds', (int) $config['process_timeout']);
    }

    /**
     * True when the value holds an env placeholder (%env(…)%), i.e. it is only known at runtime.
     */
    private function isRuntimeValue(ContainerBuilder $container, mixed $value): bool
    {
        $usedEnvs = [];
        $container->resolveEnvPlaceholders($value, null, $usedEnvs);

        return $usedEnvs !== [];
    }

    /**
     * The enabled locales as a service argument: the normalized list, or (env var) a SetupLocales::normalize() factory.
     *
     * @return Definition|list<string>
     */
    private function enabledLocalesArgument(ContainerBuilder $container, mixed $locales): array|Definition
    {
        if (!$this->isRuntimeValue($container, $locales)) {
            return SetupLocales::normalize($locales);
        }

        return (new Definition('array'))
            ->setFactory([SetupLocales::class, 'normalize'])
            ->setArguments([$locales]);
    }

    /**
     * A list derived from the enabled locales (SetupLocales::$method($base, $locales, $setupPrefix)): computed now, or
     * (env var) by a factory at runtime.
     *
     * @param 'localizedPrefixes'|'withLocalizedSetupPattern' $method
     * @param list<string> $base
     *
     * @return Definition|list<string>
     */
    private function localeDerived(ContainerBuilder $container, string $method, array $base, mixed $locales, string $setupPrefix): array|Definition
    {
        if (!$this->isRuntimeValue($container, $locales)) {
            return SetupLocales::$method($base, $locales, $setupPrefix);
        }

        return (new Definition('array'))
            ->setFactory([SetupLocales::class, $method])
            ->setArguments([$base, $locales, $setupPrefix]);
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
            ->setArgument('$processTimeoutSeconds', (int) $config['process_timeout'])
            ->setArgument('$runtimeStatePaths', $this->runtimeStatePaths($config));
    }

    /**
     * Bundle runtime state files that must never be archived nor overwritten by a restore
     * (storage_dir is added by the archiver itself).
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function runtimeStatePaths(array $config): array
    {
        $paths = [];
        foreach ([
            $config['restore']['progress_file'] ?? null,
            $config['storage']['history_file'] ?? null,
            $config['setup']['progress_file'] ?? null,
        ] as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
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
            ->setArgument('$postRestoreSetupProfile', (string) ($setup['post_restore_profile'] ?? 'post_restore'))
            ->setArgument('$workerRestartSignal', new Reference(WorkerRestartSignal::class))
            ->setArgument('$staleAfterSeconds', $this->restoreStaleAfterSeconds($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function restoreStaleAfterSeconds(array $config): int
    {
        $configured = $config['restore']['stale_after_seconds'] ?? null;
        if (is_int($configured)) {
            return $configured;
        }

        return 2 * (int) $config['process_timeout'];
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

        // v1.21: the Symfony toolbar / profiler are never site content; the setup gate redirected their XHRs, so the
        // toolbar of every gated page embedded the wizard page.
        foreach (['/_wdt/', '/_profiler/'] as $toolPrefix) {
            if (!in_array($toolPrefix, $prefixes, true)) {
                $prefixes[] = $toolPrefix;
            }
        }

        $patterns = array_values($exclusions['patterns'] ?? []);

        $localeConfig = $config['setup']['locale'] ?? [];
        if ((string) ($localeConfig['in_path'] ?? 'never') !== 'never') {
            $patterns = $this->localeDerived($container, 'withLocalizedSetupPattern', $patterns, $localeConfig['enabled'] ?? ['en'], $setupPrefix);
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
            $innerId = $custom;
        } else {
            $hash = $security['password_hash'] ?? null;
            $container->getDefinition(PasswordSiteBackupAccessGate::class)
                ->setArgument('$passwordHash', is_string($hash) ? $hash : null)
                ->setArgument('$enabled', (bool) ($security['password_protection'] ?? true));
            $innerId = PasswordSiteBackupAccessGate::class;
        }

        // v1.17+: every gate (default or custom) is wrapped: login throttle + session id rotation.
        $container->register(self::ACCESS_GATE_ID, ThrottledSiteBackupAccessGate::class)
            ->setArgument('$inner', new Reference($innerId))
            ->setArgument('$limiterFactory', $this->loginLimiterReference($container, $security['login_throttle'] ?? []));

        $container->setAlias(SiteBackupAccessGateInterface::class, self::ACCESS_GATE_ID)->setPublic(false);
    }

    /**
     * @param array<string, mixed> $throttle
     */
    private function loginLimiterReference(ContainerBuilder $container, array $throttle): ?Reference
    {
        if (!(bool) ($throttle['enabled'] ?? true)) {
            return null;
        }

        $hostLimiter = $throttle['limiter'] ?? null;
        if (is_string($hostLimiter) && $hostLimiter !== '') {
            return new Reference($hostLimiter);
        }

        // Storage pool is resolved by LoginThrottleStoragePass (cache_pool, cache.rate_limiter, cache.app).
        $container->register(self::LOGIN_LIMITER_STORAGE_ID, CacheStorage::class)
            ->addTag(LoginThrottleStoragePass::TAG, ['cache_pool' => $throttle['cache_pool'] ?? null]);

        $container->register(self::LOGIN_LIMITER_ID, RateLimiterFactory::class)
            ->setArgument('$config', [
                'id'       => 'nowo_site_backup_login',
                'policy'   => (string) ($throttle['policy'] ?? 'fixed_window'),
                'limit'    => (int) ($throttle['limit'] ?? 5),
                'interval' => (string) ($throttle['interval'] ?? '15 minutes'),
            ])
            ->setArgument('$storage', new Reference(self::LOGIN_LIMITER_STORAGE_ID))
            ->setArgument('$lockFactory', new Reference('lock.factory', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE));

        return new Reference(self::LOGIN_LIMITER_ID);
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

        // Deprecated 1.x globals (removed in 2.0): pages use the UiKit layouts (nowo_ui_layout) through the view models.
        $setupLayout = $config['web_ui']['guest_layout_template'] ?? null;
        $panelLayout = $config['web_ui']['layout_template'] ?? null;
        $display     = $config['panel']['row_actions_display'] ?? null;

        $container->getDefinition(SiteBackupTwigExtension::class)
            ->setArgument('$manager', new Reference(SiteBackupManager::class))
            ->setArgument('$setupLayoutTemplate', is_string($setupLayout) ? $setupLayout : SiteBackupView::UI_KIT_GUEST_LAYOUT)
            ->setArgument('$panelLayoutTemplate', is_string($panelLayout) ? $panelLayout : '@NowoUiKit/layout/admin.html.twig')
            ->setArgument('$cssFramework', (string) ($config['css_framework'] ?? 'custom'))
            ->setArgument('$rowActionsDisplay', is_string($display) ? $display : 'kit')
            ->setArgument('$panelClasses', $config['panel']['extra_classes'] ?? []);
    }

    /**
     * Page renderer shared by the panel and setup controllers (REQ-UI-003) + UiKit area-nav provider (site_backup).
     *
     * @param array<string, mixed> $config
     */
    private function configureView(ContainerBuilder $container, array $config): void
    {
        $display = $config['panel']['row_actions_display'] ?? null;

        $container->register(SiteBackupView::class, SiteBackupView::class)
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$pathPrefix', (string) $config['web_ui']['path_prefix'])
            ->setArgument('$layoutTemplate', $config['web_ui']['layout_template'])
            ->setArgument('$guestLayoutTemplate', $config['web_ui']['guest_layout_template'])
            ->setArgument('$templates', $config['templates'])
            ->setArgument('$rowActionsDisplay', in_array($display, SiteBackupView::ROW_ACTIONS_DISPLAYS, true) ? $display : null)
            ->addTag('nowo_ui_kit.area_nav_provider');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configurePanel(ContainerBuilder $container, array $config): void
    {
        // v1.19+: panel routes are imported through this loader so panel.enabled: false yields 404s (no routes)
        // instead of routes pointing at the removed controller service (500).
        $container->register(PanelRouteLoader::class, PanelRouteLoader::class)
            ->setArgument('$pathPrefix', (string) ($config['panel']['path_prefix'] ?? '/_site_backup'))
            ->setArgument('$enabled', (bool) $config['panel']['enabled'] && $container->hasDefinition(SiteBackupPanelController::class))
            ->addTag('routing.loader');

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
            ->setArgument('$translator', new Reference('translator', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$view', new Reference(SiteBackupView::class))
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
        $localeEnabled  = $this->enabledLocalesArgument($container, $localeConfig['enabled'] ?? ['en']);
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

        if ($container->hasDefinition(UnlocalizedDefaultLocaleSubscriber::class)) {
            if ($setupEnabled && $localeInPath === 'both' && $unlocalizedStr === 'serve') {
                $container->getDefinition(UnlocalizedDefaultLocaleSubscriber::class)
                    ->setArgument('$defaultLocale', $localeDefault)
                    ->setArgument('$unlocalizedMode', $unlocalizedStr)
                    ->setArgument(
                        '$translator',
                        new Reference('translator', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                    );
            } else {
                $container->removeDefinition(UnlocalizedDefaultLocaleSubscriber::class);
            }
        }

        $container->getDefinition(SetupMarkerManager::class)
            ->setArgument('$requiredFile', $setup['required_marker_file'])
            ->setArgument('$doneFile', $setup['done_marker_file']);

        $dbalRef = new Reference('doctrine.dbal.default_connection', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE);

        $container->getDefinition(FilesystemSetupProgressStorage::class)
            ->setArgument('$filePath', $setup['progress_file']);

        $workerMemo = $setup['worker_memo'] ?? [];
        $probeTtl   = (int) ($workerMemo['schema_probe_ttl'] ?? 60);
        $ddlTtl     = (int) ($workerMemo['progress_ddl_ttl'] ?? 3600);

        $container->getDefinition(DoctrineDbalSetupStepJournal::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$tableName', (string) ($setup['progress_steps_table'] ?? DoctrineDbalSetupStepJournal::TABLE))
            ->setArgument('$ddlMemo', $ddlTtl > 0 ? $this->workerMemo($ddlTtl) : null);

        $stepRowsEnabled = (bool) ($setup['progress_step_rows'] ?? true);
        $progressMode    = (string) ($setup['progress_storage'] ?? 'filesystem');
        if (in_array($progressMode, ['filesystem', 'cache'], true)) {
            $stepRowsEnabled = false;
        }

        $container->getDefinition(DoctrineDbalSetupProgressStorage::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$tableName', (string) ($setup['progress_table'] ?? DoctrineDbalSetupProgressStorage::TABLE))
            ->setArgument('$stepJournal', new Reference(DoctrineDbalSetupStepJournal::class))
            ->setArgument('$stepRowsEnabled', $stepRowsEnabled)
            ->setArgument('$ddlMemo', $ddlTtl > 0 ? $this->workerMemo($ddlTtl) : null);

        $container->getDefinition(ChainSetupProgressStorage::class)
            ->setArgument('$filesystem', new Reference(FilesystemSetupProgressStorage::class))
            ->setArgument('$doctrine', new Reference(DoctrineDbalSetupProgressStorage::class));

        $usesCache = in_array($progressMode, ['cache', 'cache_doctrine'], true);
        if ($usesCache) {
            $poolId = is_string($setup['progress_cache_pool'] ?? null) && $setup['progress_cache_pool'] !== ''
                ? $setup['progress_cache_pool']
                : 'cache.app';

            $container->getDefinition(CacheSetupProgressStorage::class)
                ->setArgument('$cache', new Reference($poolId))
                ->setArgument('$cacheKey', (string) ($setup['progress_cache_key'] ?? CacheSetupProgressStorage::DEFAULT_CACHE_KEY))
                ->setArgument('$ttlSeconds', (int) ($setup['progress_cache_ttl'] ?? 86400));

            $container->getDefinition(CacheDoctrineSetupProgressStorage::class)
                ->setArgument('$cache', new Reference(CacheSetupProgressStorage::class))
                ->setArgument('$doctrine', new Reference(DoctrineDbalSetupProgressStorage::class))
                ->setArgument(
                    '$schemaChecker',
                    new Reference(SchemaExistenceCheckerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                );
        } else {
            $container->removeDefinition(CacheSetupProgressStorage::class);
            $container->removeDefinition(CacheDoctrineSetupProgressStorage::class);
        }

        $progressStorage = match ($progressMode) {
            'doctrine'       => DoctrineDbalSetupProgressStorage::class,
            'chain'          => ChainSetupProgressStorage::class,
            'cache'          => CacheSetupProgressStorage::class,
            'cache_doctrine' => CacheDoctrineSetupProgressStorage::class,
            default          => FilesystemSetupProgressStorage::class,
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
            ->setArgument('$healthyMemo', $probeTtl > 0 ? $this->workerMemo($probeTtl) : null)
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 90]);

        $container->getDefinition(DoctrineSchemaEmptyDetector::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$enabled', (bool) ($setup['detectors']['doctrine_schema_empty'] ?? false))
            ->setArgument('$healthyMemo', $probeTtl > 0 ? $this->workerMemo($probeTtl) : null)
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 80]);

        $container->getDefinition(IncompleteSetupProgressDetector::class)
            ->setArgument('$progressStorage', new Reference(SetupProgressStorageInterface::class))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$enabled', (bool) ($setup['detectors']['incomplete_progress'] ?? true))
            ->addTag('nowo.site_backup.setup_need_detector', ['priority' => 70]);

        // Default durable store before evaluator wiring (host apps may replace the alias later).
        $container->setAlias(DurableSetupDoneStoreInterface::class, NullDurableSetupDoneStore::class)->setPublic(false);

        // Built-ins (above) + host apps via SetupNeedDetectorInterface / #[AsSetupNeedDetector].
        // Distinct from profile tab checkers (SetupTabCheckerInterface / checker: YAML).
        // short_circuit_when_done: skip detectors when setup.done or durable store says complete.
        $container->getDefinition(SetupWizardReopener::class)
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$progressStorage', new Reference(SetupProgressStorageInterface::class))
            ->setArgument('$durableDoneStore', new Reference(DurableSetupDoneStoreInterface::class))
            ->setPublic(true);

        $container->getDefinition(SetupNeedEvaluator::class)
            ->setArgument('$detectors', new TaggedIteratorArgument('nowo.site_backup.setup_need_detector'))
            ->setArgument('$setupEnabled', (bool) $setup['enabled'])
            ->setArgument('$shortCircuitWhenDone', (bool) ($setup['short_circuit_when_done'] ?? true))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$durableDoneStore', new Reference(DurableSetupDoneStoreInterface::class))
            ->setArgument('$reopenWhenDetectorRequires', (bool) ($setup['reopen_when_detector_requires'] ?? false))
            ->setArgument('$reopener', new Reference(SetupWizardReopener::class));

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
            ->setArgument('$defaultAdvanceMode', $globalAdvance)
            ->setArgument('$workerRestartSignal', new Reference(WorkerRestartSignal::class));

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

        $this->configureDurableDoneAndColdStart($container, $setup, $localeEnabled);

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
            ->setArgument('$translator', new Reference('translator', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
            ->setArgument('$view', new Reference(SiteBackupView::class))
            ->setPublic(true);
    }

    /**
     * @param array<string, mixed> $setup
     * @param Definition|list<string> $localeEnabled literal list, or a SetupLocales factory (env var locales)
     */
    private function configureDurableDoneAndColdStart(ContainerBuilder $container, array $setup, array|Definition $localeEnabled): void
    {
        // Alias may already point at NullDurableSetupDoneStore from setup wiring; keep default unless host replaced it.
        if (!$container->hasAlias(DurableSetupDoneStoreInterface::class) && !$container->hasDefinition(DurableSetupDoneStoreInterface::class)) {
            $container->setAlias(DurableSetupDoneStoreInterface::class, NullDurableSetupDoneStore::class)->setPublic(false);
        }

        $durableDone = $setup['durable_done'] ?? [];
        $coldStart   = $setup['cold_start'] ?? [];

        $container->getDefinition(SetupDbDoneGuard::class)
            ->setArgument('$durableDoneStore', new Reference(DurableSetupDoneStoreInterface::class))
            ->setArgument('$needEvaluator', new Reference(SetupNeedEvaluator::class))
            ->setArgument('$markers', new Reference(SetupMarkerManager::class))
            ->setArgument('$progressStorage', new Reference(SetupProgressStorageInterface::class));

        if ((bool) ($durableDone['enabled'] ?? false)) {
            $redirectTarget = is_string($durableDone['redirect_target'] ?? null) && $durableDone['redirect_target'] !== ''
                ? $durableDone['redirect_target']
                : '/';

            $container->getDefinition(SetupDbDoneRedirectSubscriber::class)
                ->setArgument('$setupDbDoneGuard', new Reference(SetupDbDoneGuard::class))
                ->setArgument('$setupPathPrefix', $setup['path_prefix'])
                ->setArgument('$enabledLocales', $localeEnabled)
                ->setArgument('$redirectTarget', $redirectTarget)
                ->addTag('kernel.event_subscriber');
        } else {
            $container->removeDefinition(SetupDbDoneRedirectSubscriber::class);
        }

        if (!(bool) ($coldStart['enabled'] ?? false)) {
            $container->removeDefinition(ColdStartSchemaGateSubscriber::class);
            $container->removeDefinition(MysqlSchemaExistenceChecker::class);

            return;
        }

        $dbalRef = new Reference('doctrine.dbal.default_connection', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE);

        $mysqlHost = $coldStart['mysql_host'] ?? null;
        $mysqlPort = $coldStart['mysql_port'] ?? 3306;
        $mysqlUser = $coldStart['mysql_user'] ?? null;
        $mysqlPass = $coldStart['mysql_password'] ?? null;
        $mysqlDb   = $coldStart['mysql_database'] ?? null;

        $container->getDefinition(MysqlSchemaExistenceChecker::class)
            ->setArgument('$connection', $dbalRef)
            ->setArgument('$host', is_string($mysqlHost) && $mysqlHost !== '' ? $mysqlHost : null)
            // An env placeholder (%env(int:DB_PORT)%) is a string here: kept, resolved at runtime.
            ->setArgument('$port', is_int($mysqlPort) || $this->isRuntimeValue($container, $mysqlPort) ? $mysqlPort : 3306)
            ->setArgument('$user', is_string($mysqlUser) && $mysqlUser !== '' ? $mysqlUser : null)
            ->setArgument('$password', is_string($mysqlPass) ? $mysqlPass : null)
            ->setArgument('$database', is_string($mysqlDb) && $mysqlDb !== '' ? $mysqlDb : null)
            ->setArgument('$requireApplicationTables', (bool) ($coldStart['require_application_tables'] ?? true));

        $probeTtl = (int) ($setup['worker_memo']['schema_probe_ttl'] ?? 60);
        if ($probeTtl > 0) {
            $container->register(MemoizedSchemaExistenceChecker::class, MemoizedSchemaExistenceChecker::class)
                ->setArgument('$inner', new Reference(MysqlSchemaExistenceChecker::class))
                ->setArgument('$memo', $this->workerMemo($probeTtl));
            $container->setAlias(SchemaExistenceCheckerInterface::class, MemoizedSchemaExistenceChecker::class)->setPublic(false);
        } else {
            $container->setAlias(SchemaExistenceCheckerInterface::class, MysqlSchemaExistenceChecker::class)->setPublic(false);
        }

        $safePrefixes = array_values($coldStart['safe_path_prefixes'] ?? []);

        $container->getDefinition(ColdStartSchemaGateSubscriber::class)
            ->setArgument('$schemaChecker', new Reference(SchemaExistenceCheckerInterface::class))
            ->setArgument('$pathPrefixResolver', new Reference(SetupPathPrefixResolver::class))
            ->setArgument('$setupPathPrefix', $setup['path_prefix'])
            ->setArgument('$safePathPrefixes', $safePrefixes)
            ->setArgument('$enabledLocales', $localeEnabled)
            ->setArgument('$stopPropagation', (bool) ($coldStart['stop_propagation'] ?? true))
            ->addTag('kernel.event_subscriber');
    }
}
