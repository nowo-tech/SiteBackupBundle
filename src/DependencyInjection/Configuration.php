<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\View\SetupTokenGate;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Throwable;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Bundle configuration tree under alias `nowo_site_backup`.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_site_backup';

    /** @var list<string> */
    public const CSS_FRAMEWORKS = ['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'];

    /** @var list<string> */
    public const ROW_ACTIONS_DISPLAYS = ['text', 'icon', 'icon_text', 'kit'];

    private const DEPRECATION_PACKAGE = 'nowo-tech/site-backup-bundle';

    private const DEPRECATION_VERSION = '1.21';

    /**
     * Deprecated 1.x templates.* key => web_ui.templates page id (REQ-UI-003).
     *
     * @var array<string, string>
     */
    public const LEGACY_TEMPLATE_PAGES = [
        'restore_page'  => 'restore_page',
        'panel_index'   => 'index',
        'panel_login'   => 'login',
        'panel_history' => 'history',
        'setup_wizard'  => 'setup_wizard',
        'setup_done'    => 'setup_done',
        'setup_token'   => 'setup_token',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->info('Nowo Site Backup Bundle configuration.')
            ->beforeNormalization()
                ->always(static fn (mixed $v): mixed => is_array($v) ? self::mapLegacyKeys($v) : $v)
            ->end()
            ->validate()
                ->always(static function (array $v): array {
                    // Internal readers keep using panel.enabled / panel.path_prefix (synced from the canonical keys).
                    $v['panel']['enabled']     = $v['web_ui']['enabled'];
                    $v['panel']['path_prefix'] = $v['web_ui']['path_prefix'];

                    return $v;
                })
            ->end()
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch: when false the restore loading page is never shown.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('default_message')
                    ->info('Translation id (domain NowoSiteBackupBundle) or literal for the public restore loading page, rendered as its lead text above the current step (v1.19+). Default: restore.page.message.')
                    ->defaultValue('restore.page.message')
                ->end()
                ->integerNode('status_code')
                    ->info('HTTP status code while restore mode is active.')
                    ->defaultValue(503)
                    ->min(400)
                    ->max(599)
                ->end()
                ->integerNode('subscriber_priority')
                    ->info('kernel.request listener priority (default 31: after router).')
                    ->defaultValue(31)
                ->end()
                ->integerNode('process_timeout')
                    ->info('Timeout in seconds for tar / dump subprocesses (REQ-RUNTIME-001).')
                    ->defaultValue(600)
                    ->min(30)
                ->end()
                ->booleanNode('bump_time_limit')
                    ->info('When true (v1.16+), main requests under the setup path (incl. /{locale} variants) and the backup panel path call set_time_limit(bump_time_limit_seconds ?? process_timeout) so FrankenPHP workers / short prod max_execution_time do not kill dump, restore, migrate subprocesses mid-pipe. Default false (BC).')
                    ->defaultFalse()
                ->end()
                ->integerNode('bump_time_limit_seconds')
                    ->info('Seconds passed to set_time_limit() when bump_time_limit is true. null = process_timeout; 0 = unlimited.')
                    ->defaultNull()
                    ->min(0)
                ->end()
                ->arrayNode('security_guard')
                    ->info('Opt-in production safety guard (v1.16+): outside local_environments, refuse to serve HTTP / run console commands while the setup token, panel password hash or APP_SECRET are empty or documented placeholders.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('local_environments')
                            ->info('kernel.environment values where the guard is skipped.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['dev', 'test'])
                        ->end()
                        ->booleanNode('require_setup_token')
                            ->info('Require a non-empty setup.setup_token when the setup wizard is enabled.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('forbidden_setup_tokens')
                            ->info('Documented / local setup tokens that must never reach production (e.g. the value in your .env.dist).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('require_panel_password')
                            ->info('Require a non-empty security.password_hash when the panel and password_protection are enabled (ignored with a custom access_gate).')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('forbidden_password_hashes')
                            ->info('Documented / local panel password hashes that must never reach production.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('check_app_secret')->defaultTrue()->end()
                        ->scalarNode('app_secret')
                            ->info('Value checked as APP_SECRET. null = %env(default::APP_SECRET)%.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('forbidden_app_secrets')
                            ->info('Placeholder APP_SECRET values that must never reach production.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ThisTokenIsNotSoSecretChangeIt', 'ChangeMe', '!ChangeMe!', 'ChangeMePleaseUseARealSecret'])
                        ->end()
                        ->integerNode('app_secret_min_length')->defaultValue(16)->min(0)->end()
                        ->arrayNode('skip_console_commands')
                            ->info('Console commands that skip the guard (image builds warm the cache without runtime secrets; hash-password generates the hash).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['cache:clear', 'cache:warmup', 'assets:install', 'nowo:site-backup:hash-password'])
                        ->end()
                        ->booleanNode('check_password_hash_shape')
                            ->info('v1.20+: refuse a panel password_hash that password_get_info() does not recognise (e.g. a bcrypt hash cut by Docker Compose "$" interpolation).')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('extra_secrets')
                            ->info('v1.20+: more app secrets checked outside local environments (empty = skipped unless required).')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('value')->info('Usually an env placeholder, e.g. %env(default::MERCURE_JWT_SECRET)%.')->defaultNull()->end()
                                    ->integerNode('min_length')->defaultValue(0)->min(0)->end()
                                    ->arrayNode('forbidden')->scalarPrototype()->end()->defaultValue([])->end()
                                    ->booleanNode('required')->defaultFalse()->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('dsn_credentials')
                            ->info('v1.20+: DSNs (Redis, AMQP, …) that must carry a password and no documented default credentials outside local environments; other schemes are skipped.')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('value')->info('Usually an env placeholder, e.g. %env(default::MESSENGER_TRANSPORT_DSN)%.')->defaultNull()->end()
                                    ->arrayNode('schemes')->scalarPrototype()->end()->defaultValue(['redis', 'rediss', 'amqp', 'amqps'])->end()
                                    ->arrayNode('forbidden_credentials')->info('"user:password" pairs, e.g. guest:guest.')->scalarPrototype()->end()->defaultValue(['guest:guest'])->end()
                                    ->scalarNode('password_fallback')->info('A non-empty value here also satisfies the password check (e.g. %env(default::REDIS_PASSWORD)% when REDIS_URL is built without it).')->defaultNull()->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->enumNode('css_framework')
                    ->info('Deprecated since 1.21: set nowo_ui_kit.css_framework. When set here (and not on UiKit) it is still seeded into nowo_ui_kit.css_framework / icon_set. null = UiKit decides.')
                    ->values([...self::CSS_FRAMEWORKS, null])
                    ->defaultNull()
                    ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.css_framework" instead.')
                ->end()
                ->append($this->webUiNode())
                ->arrayNode('backup')
                    ->info('What is included in an integral backup.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('include_paths')
                            ->info('Paths relative to kernel.project_dir to include. Empty list or "." = entire project (minus exclude_patterns). Omitting the key keeps the selective defaults below.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['config', 'public', 'templates', 'translations', 'src', 'migrations', 'composer.json', 'composer.lock', '.env'])
                        ->end()
                        ->arrayNode('exclude_patterns')
                            ->info('Finder notPath / fnmatch patterns to skip inside includes.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['var/cache/*', 'var/log/*', 'var/site-backup/*', 'vendor/*', 'node_modules/*', '.git/*'])
                        ->end()
                        ->scalarNode('storage_dir')
                            ->info('Directory where .tar.gz + .meta.json artifacts are stored.')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/archives')
                        ->end()
                        ->scalarNode('database_dump_command')
                            ->info('Shell command that writes SQL to stdout (e.g. mysqldump …, or the built-in "php bin/console nowo:site-backup:db-dump"). Empty = no DB dump.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('restore')
                    ->info('Safe restore options.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('progress_file')
                            ->info('JSON progress file used by the loading UI (keep outside overwritten paths if possible).')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/restore-progress.json')
                        ->end()
                        ->arrayNode('protected_paths')
                            ->info('Relative paths never overwritten during apply (in addition to var/site-backup/).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['.env.local', 'var/site-backup'])
                        ->end()
                        ->integerNode('stale_after_seconds')
                            ->info('v1.19+: an active restore whose progress was not updated for this many seconds is treated as abandoned (dead worker / killed process): the loading page stops being served and a new restore may start. null = 2 × process_timeout; 0 = never (pre-1.19 behaviour).')
                            ->defaultNull()
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('panel')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Deprecated alias of web_ui.enabled.')
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.enabled" instead.')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('Deprecated alias of web_ui.path_prefix.')
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.path_prefix" instead.')
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Deprecated alias of web_ui.layout_template.')
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.layout_template" (null = nowo_ui_kit.layouts.admin) instead.')
                        ->end()
                        ->enumNode('row_actions_display')
                            ->info('Deprecated since 1.21: archive row actions follow nowo_ui_kit.row_actions_display. text | icon | icon_text still force a display on the Verify / Restore / Delete actions; kit / null = UiKit.')
                            ->values([...self::ROW_ACTIONS_DISPLAYS, null])
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.row_actions_display" instead.')
                        ->end()
                        ->arrayNode('extra_classes')
                            ->info('Deprecated since 1.21: bridged into nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes (card → card, table → table, input → input, button → btn_primary, btn_secondary and action).')
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes" (UiKit theme slots) instead.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('card')->defaultValue('')->end()
                                ->scalarNode('table')->defaultValue('')->end()
                                ->scalarNode('button')->defaultValue('')->end()
                                ->scalarNode('input')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('exclusions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('paths')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('path_prefixes')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('routes')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('patterns')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('ips')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 roles + optional ops password gate. Password gate is additional to access_roles.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Symfony roles granted access to the panel (at least one). Empty list is fail-closed (deny via access checker). Use allow_unauthenticated for demos only.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Optional service id implementing SiteBackupAccessCheckerInterface. null = role-based default (or AllowAll when allow_unauthenticated).')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV/DEMO only: skip Symfony Security role check (password gate may still apply). Never true in production.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('password_protection')->defaultTrue()->end()
                        ->scalarNode('password_hash')->defaultNull()->end()
                        ->scalarNode('access_gate')->defaultNull()->end()
                        ->arrayNode('login_throttle')
                            ->info('v1.17+: per-client-IP rate limit of panel password attempts (HTTP 429 + Retry-After when exceeded; reset on success). Only applies while the gate reports isProtectionEnabled(). The session id is always rotated after a successful login.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->integerNode('limit')
                                    ->info('Maximum failed + successful attempts per client IP within interval.')
                                    ->defaultValue(5)
                                    ->min(1)
                                ->end()
                                ->scalarNode('interval')
                                    ->info('Window length, any relative date format (e.g. "15 minutes", "1 hour").')
                                    ->defaultValue('15 minutes')
                                    ->cannotBeEmpty()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !self::isValidInterval($v))
                                        ->thenInvalid('Invalid login_throttle.interval %s (use e.g. "15 minutes").')
                                    ->end()
                                ->end()
                                ->enumNode('policy')
                                    ->values(['fixed_window', 'sliding_window'])
                                    ->defaultValue('fixed_window')
                                ->end()
                                ->scalarNode('limiter')
                                    ->info('Optional host RateLimiterFactoryInterface service id (e.g. "limiter.site_backup_login"). When set, limit/interval/policy/cache_pool are ignored.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('cache_pool')
                                    ->info('PSR-6 cache pool service id for the built-in limiter storage. null = "cache.rate_limiter", else "cache.app".')
                                    ->defaultNull()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('history_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/history.jsonl')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->info('Deprecated since 1.21: per-page templates moved to web_ui.templates.<page>, layouts to web_ui.layout_template / guest_layout_template.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('restore_page')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.restore_page" instead.')
                        ->end()
                        ->scalarNode('panel_layout')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.layout_template" instead.')
                        ->end()
                        ->scalarNode('panel_index')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.index" instead.')
                        ->end()
                        ->scalarNode('panel_login')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.login" instead.')
                        ->end()
                        ->scalarNode('panel_history')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.history" instead.')
                        ->end()
                        ->scalarNode('setup_wizard')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.setup_wizard" instead.')
                        ->end()
                        ->scalarNode('setup_layout')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.guest_layout_template" instead.')
                        ->end()
                        ->scalarNode('setup_admin')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, it has no effect (the setup steps render inside setup/wizard.html.twig).')
                        ->end()
                        ->scalarNode('setup_sample')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, it has no effect (the setup steps render inside setup/wizard.html.twig).')
                        ->end()
                        ->scalarNode('setup_database')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, it has no effect (the setup steps render inside setup/wizard.html.twig).')
                        ->end()
                        ->scalarNode('setup_done')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.setup_done" instead.')
                        ->end()
                        ->scalarNode('setup_token')
                            ->defaultNull()
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.templates.setup_token" instead.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('setup')
                    ->info('Cold-start / post-restore setup wizard.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('path_prefix')->defaultValue('/_setup')->cannotBeEmpty()->end()
                        ->scalarNode('layout_template')
                            ->info('Deprecated alias of web_ui.guest_layout_template.')
                            ->setDeprecated(self::DEPRECATION_PACKAGE, self::DEPRECATION_VERSION, 'The "%path%.%node%" option is deprecated, use "nowo_site_backup.web_ui.guest_layout_template" (null = nowo_ui_kit.layouts.guest) instead.')
                        ->end()
                        ->booleanNode('require_done_marker')
                            ->info('When true, missing setup.done forces the wizard (fresh clones). Default false so adding the bundle does not lock existing apps.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('short_circuit_when_done')
                            ->info('When true (default), SetupNeedEvaluator skips all detectors if setup.done exists or DurableSetupDoneStoreInterface::isDone() is true. Set false if a host detector must re-open the gate after done.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('reopen_when_detector_requires')
                            ->info('When true (v1.15+), short_circuit_when_done no longer hides detectors: if setup.done / the durable store says complete but a tagged SetupNeedDetector still requires setup, setup.done and the durable store are cleared and progress is reset so the wizard can run again (SetupWizardReopener). Default false (BC). Costs one detector pass per request (the Doctrine detectors reuse a healthy answer for setup.worker_memo.schema_probe_ttl).')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('worker_memo')
                            ->info('Per-worker TTL memos (v1.16+) that survive kernel.reset in FrankenPHP worker mode, so schema probes and the progress-table DDL do not run on every request.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('schema_probe_ttl')
                                    ->info('Seconds a positive answer (schema exists / DB reachable / tables present) from the cold-start checker and the doctrine_connect / doctrine_schema_empty detectors is reused. Negative answers are never cached. 0 = probe every time.')
                                    ->defaultValue(60)
                                    ->min(0)
                                ->end()
                                ->integerNode('progress_ddl_ttl')
                                    ->info('Seconds the Doctrine progress / step-journal "CREATE TABLE IF NOT EXISTS" is remembered across requests (a dropped table is still re-created on the first failing query). 0 = once per request (pre-1.16 behaviour).')
                                    ->defaultValue(3600)
                                    ->min(0)
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('brand_name')->defaultValue('Site Setup')->end()
                        ->scalarNode('setup_token')
                            ->info('Optional shared secret for /_setup (?token= or X-Setup-Token).')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('token_gate')
                            ->info('Setup-token gate page (v1.22+): optional illustration above the auth panel and the how-to steps of its <details> disclosure.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('illustration')
                                    ->info('Asset path of the illustration (string shorthand = src), rendered as <picture>: WebP source + PNG/JPEG fallback <img> with width/height. Null = no illustration.')
                                    ->beforeNormalization()
                                        ->ifString()
                                        ->then(static fn (string $v): array => ['src' => $v])
                                    ->end()
                                    ->beforeNormalization()
                                        ->ifTrue(static fn (mixed $v): bool => $v === null || $v === false || $v === '')
                                        ->thenUnset()
                                    ->end()
                                    ->canBeUnset()
                                    ->children()
                                        ->scalarNode('src')
                                            ->info('Fallback image asset path (PNG / JPEG), resolved with asset(), e.g. illustrations/error-403.png.')
                                            ->isRequired()
                                            ->cannotBeEmpty()
                                        ->end()
                                        ->scalarNode('webp')
                                            ->info('WebP <source>: an asset path, true = src with a .webp extension, null / false (default) = none (1.22.1: no longer derived by default).')
                                            ->defaultNull()
                                        ->end()
                                        ->integerNode('width')->info('Intrinsic width in pixels (reserves the layout box).')->defaultNull()->min(1)->end()
                                        ->integerNode('height')->info('Intrinsic height in pixels (reserves the layout box).')->defaultNull()->min(1)->end()
                                        ->scalarNode('alt')
                                            ->info('Alt text: translation key (or text) of token_gate.translation_domain.')
                                            ->defaultValue(SetupTokenGate::DEFAULT_ILLUSTRATION_ALT)
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('howto_steps')
                                    ->info('How-to steps (translation keys or translated texts of token_gate.translation_domain, %path% = <setup prefix>?token=…). Empty list = no how-to disclosure.')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(SetupTokenGate::DEFAULT_HOWTO_STEPS)
                                ->end()
                                ->scalarNode('translation_domain')
                                    ->info('Translation domain of the illustration alt text and the how-to steps.')
                                    ->defaultValue(SiteBackupView::BUNDLE)
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('progress_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup-progress.json')
                        ->end()
                        ->enumNode('progress_storage')
                            ->values(['filesystem', 'doctrine', 'chain', 'cache', 'cache_doctrine'])
                            ->info('filesystem = JSON in var/; doctrine = DBAL; chain = JSON+DB; cache = PSR-6 pool; cache_doctrine = cache+DB (prefer DB on load).')
                            ->defaultValue('filesystem')
                        ->end()
                        ->scalarNode('progress_table')
                            ->info('DBAL table name when progress_storage is doctrine, chain, or cache_doctrine.')
                            ->defaultValue('nowo_site_backup_setup_progress')
                        ->end()
                        ->booleanNode('progress_step_rows')
                            ->info('When true (default) and progress_storage uses Doctrine, upsert per-step rows (runtime DDL — not Symfony migrations; safe before host schema exists).')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('progress_steps_table')
                            ->info('DBAL table for per-step journal when progress_step_rows is true.')
                            ->defaultValue('nowo_site_backup_setup_step')
                        ->end()
                        ->scalarNode('progress_cache_pool')
                            ->info('PSR-6 cache pool service id when progress_storage is cache or cache_doctrine (default cache.app).')
                            ->defaultValue('cache.app')
                        ->end()
                        ->scalarNode('progress_cache_key')
                            ->defaultValue('nowo_site_backup.setup_progress')
                        ->end()
                        ->integerNode('progress_cache_ttl')
                            ->info('TTL seconds for cache / cache_doctrine progress (default 86400).')
                            ->defaultValue(86400)
                            ->min(60)
                        ->end()
                        ->scalarNode('required_marker_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup.required')
                        ->end()
                        ->scalarNode('done_marker_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup.done')
                        ->end()
                        ->arrayNode('durable_done')
                            ->info('Close the wizard from a host durable store (survives var/ wipe).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('redirect_target')
                                    ->info('Target when durable done closes the wizard (default /).')
                                    ->defaultValue('/')
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('cold_start')
                            ->info('Gate requests when the MySQL application schema is not reachable yet.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->booleanNode('stop_propagation')->defaultTrue()->end()
                                ->booleanNode('require_application_tables')
                                    ->info('When true (default), an empty MySQL schema (only nowo_site_backup_* tables) stays cold-start until migrations create app tables.')
                                    ->defaultTrue()
                                ->end()
                                ->arrayNode('safe_path_prefixes')
                                    ->info('Paths allowed through without redirect when schema is missing.')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([
                                        '/health/',
                                        '/api/',
                                        '/_wdt',
                                        '/_profiler',
                                        '/build/',
                                        '/assets/',
                                        '/_error',
                                        '/favicon.ico',
                                    ])
                                ->end()
                                ->scalarNode('mysql_host')->defaultNull()->end()
                                ->integerNode('mysql_port')->defaultValue(3306)->min(1)->max(65535)->end()
                                ->scalarNode('mysql_user')->defaultNull()->end()
                                ->scalarNode('mysql_password')->defaultNull()->end()
                                ->scalarNode('mysql_database')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->scalarNode('php_binary')->defaultValue('php')->end()
                        ->scalarNode('admin_provisioner')
                            ->info('Service id implementing AdminUserProvisionerInterface.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('trigger_after_restore')->defaultTrue()->end()
                        ->scalarNode('post_restore_profile')->defaultValue('post_restore')->end()
                        ->arrayNode('detectors')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('marker')->defaultTrue()->end()
                                ->booleanNode('doctrine_connect')->defaultFalse()->end()
                                ->booleanNode('doctrine_schema_empty')->defaultFalse()->end()
                                ->booleanNode('incomplete_progress')
                                    ->info('When true, unfinished setup progress (running/waiting/failed) forces the site gate to /_setup.')
                                    ->defaultTrue()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('default_profile')->defaultValue('fresh_install')->end()
                        ->enumNode('advance_mode')
                            ->values(['automatic', 'manual'])
                            ->info('automatic = chain auto tabs until interaction; manual = one auto tab per Continuar.')
                            ->defaultValue('automatic')
                        ->end()
                        ->arrayNode('locale')
                            ->info('Locale-in-path for setup wizard routes (mirrors AuthKit locale config).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->enumNode('in_path')
                                    ->values(['never', 'always', 'both'])
                                    ->info('never = bare prefix only (BC); always = /{_locale}{prefix}; both = dual URLs.')
                                    ->defaultValue('never')
                                ->end()
                                ->scalarNode('default')
                                    ->info('Default locale for localized routes.')
                                    ->defaultValue('en')
                                    ->cannotBeEmpty()
                                ->end()
                                ->variableNode('enabled')
                                    ->info('List of enabled locale codes, or a parameter / env var holding one: "%app.locales%", "%kernel.enabled_locales%", "%env(json:APP_LOCALES)%" (resolved at runtime).')
                                    ->defaultValue(['en'])
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) && !self::isLocaleList($v))
                                        ->thenInvalid('Invalid nowo_site_backup.setup.locale.enabled %s: use a list of locale codes or a parameter / env placeholder holding one.')
                                    ->end()
                                ->end()
                                ->enumNode('unlocalized')
                                    ->values(['serve', 'redirect'])
                                    ->info('When in_path=both: serve renders with default locale; redirect bounces to /{locale}/….')
                                    ->defaultValue('redirect')
                                ->end()
                            ->end()
                        ->end()
                        ->append($this->setupProfilesNode())
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * REQ-UI-003 canonical Web UI keys of the panel and the guest pages (login, setup wizard).
     */
    private function webUiNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('web_ui');
        $node
            ->info('REQ-UI-003 Web UI: the panel and setup pages extend the UiKit scaffolds; theme them once in nowo_ui_kit.theme.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Register the backup panel routes (/, /history, /create, /restore/{id}, /delete/{id}, /progress.json).')
                    ->defaultTrue()
                ->end()
                ->scalarNode('path_prefix')
                    ->info('URL prefix of the backup panel.')
                    ->defaultValue(SiteBackupView::DEFAULT_PATH_PREFIX)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('layout_template')
                    ->info('Layout the panel scaffolds extend instead of nowo_ui_kit.layouts.admin (null = UiKit layout). It must render the UiKit canonical blocks; a 1.x layout without nowo_ui_content is bridged (deprecated).')
                    ->defaultNull()
                ->end()
                ->scalarNode('guest_layout_template')
                    ->info('Layout of the guest pages (panel login, setup wizard, setup token) instead of nowo_ui_kit.layouts.guest (null = UiKit layout). The setup wizard renders it before the app has a database: it must not need DB services. A 1.x layout without nowo_ui_content is bridged (deprecated).')
                    ->defaultNull()
                ->end()
                ->arrayNode('templates')
                    ->info('Per-page template override (page => template): ' . implode(', ', array_keys(SiteBackupView::TEMPLATES)) . '. Prefer a thin {% extends \'@!NowoSiteBackup/…\' %} that overrides a data block.')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('page')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(SiteBackupView::TEMPLATES)) !== [])
                        ->then(static function (array $v): array {
                            throw new InvalidArgumentException(sprintf('Unknown nowo_site_backup.web_ui.templates page(s): %s. Allowed: %s.', implode(', ', array_diff(array_keys($v), array_keys(SiteBackupView::TEMPLATES))), implode(', ', array_keys(SiteBackupView::TEMPLATES))));
                        })
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Copies the deprecated 1.x keys into the canonical web_ui keys (the canonical key wins when both are set). The
     * old keys stay in place so their deprecation is reported.
     *
     * @param array<mixed> $v
     *
     * @return array<mixed>
     */
    private static function mapLegacyKeys(array $v): array
    {
        $panel     = is_array($v['panel'] ?? null) ? $v['panel'] : [];
        $setup     = is_array($v['setup'] ?? null) ? $v['setup'] : [];
        $templates = is_array($v['templates'] ?? null) ? $v['templates'] : [];
        $webUi     = is_array($v['web_ui'] ?? null) ? $v['web_ui'] : [];
        $pages     = is_array($webUi['templates'] ?? null) ? $webUi['templates'] : [];

        foreach (['enabled', 'path_prefix', 'layout_template'] as $key) {
            if (array_key_exists($key, $panel) && !array_key_exists($key, $webUi)) {
                $webUi[$key] = $panel[$key];
            }
        }
        if (!array_key_exists('layout_template', $webUi) && isset($templates['panel_layout'])) {
            $webUi['layout_template'] = $templates['panel_layout'];
        }
        if (!array_key_exists('guest_layout_template', $webUi)) {
            $guest = $setup['layout_template'] ?? $templates['setup_layout'] ?? null;
            if ($guest !== null) {
                $webUi['guest_layout_template'] = $guest;
            }
        }
        foreach (['layout_template', 'guest_layout_template'] as $key) {
            if (array_key_exists($key, $webUi)) {
                // The 1.x bundle layouts (standalone HTML pages) are gone: null = UiKit layout.
                $webUi[$key] = SiteBackupView::normalizeLayout($webUi[$key]);
            }
        }
        foreach (self::LEGACY_TEMPLATE_PAGES as $old => $page) {
            if (is_string($templates[$old] ?? null) && $templates[$old] !== '' && !array_key_exists($page, $pages)) {
                $pages[$page] = $templates[$old];
            }
        }
        if ($pages !== []) {
            $webUi['templates'] = $pages;
        }
        if ($webUi !== []) {
            $v['web_ui'] = $webUi;
        }

        return $v;
    }

    private function setupProfilesNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('profiles');
        $node
            ->useAttributeAsKey('name')
            ->defaultValue($this->defaultSetupProfiles());

        $profile         = $node->arrayPrototype();
        $profileChildren = $profile->children();
        $profileChildren
            ->scalarNode('advance_mode')
                ->info('Override setup.advance_mode for this profile (automatic|manual).')
                ->defaultNull()
                ->validate()
                    ->ifTrue(static fn (mixed $v): bool => $v !== null && !in_array($v, ['automatic', 'manual'], true))
                    ->thenInvalid('advance_mode must be "automatic" or "manual".')
                ->end()
            ->end();

        $steps = $profileChildren->arrayNode('steps');
        $steps
            ->info('Legacy step list. Ignored when tabs is non-empty.')
            ->defaultValue([]);
        $this->addSetupStepKeys($steps->arrayPrototype()->ignoreExtraKeys(false)->children(), true);

        $tabs = $profileChildren->arrayNode('tabs');
        $tabs
            ->info('Ordered wizard tabs (preferred). Each may bind checker/template/runner.')
            ->defaultValue([]);
        $tabChildren = $tabs->arrayPrototype()->ignoreExtraKeys(false)->children();
        $this->addSetupStepKeys($tabChildren, true);
        $tabChildren
            ->scalarNode('checker')
                ->info('Service id / FQCN implementing SetupTabCheckerInterface.')
                ->defaultNull()
            ->end()
            ->scalarNode('template')
                ->info('Twig template for custom waiting_input UI.')
                ->defaultNull()
            ->end()
            ->scalarNode('label_domain')
                ->info('Translation domain for label/description (default NowoSiteBackupBundle).')
                ->defaultNull()
            ->end()
            ->scalarNode('description')
                ->info('Optional translation id for tab subtitle.')
                ->defaultNull()
            ->end();
        $runner = $tabChildren->arrayNode('runner');
        $runner
            ->info('Optional nested step config when type is custom (e.g. console / sql_file).')
            ->addDefaultsIfNotSet();
        $this->addSetupStepKeys($runner->children(), false);

        return $node;
    }

    private function addSetupStepKeys(NodeBuilder $children, bool $typeRequired): void
    {
        if ($typeRequired) {
            $children->scalarNode('type')->isRequired()->end();
        } else {
            $children->scalarNode('type')->defaultNull()->end();
        }

        $children
            ->scalarNode('id')->defaultNull()->end()
            ->scalarNode('label')->defaultNull()->end()
            ->variableNode('command')->defaultNull()->end()
            ->variableNode('commands')->defaultNull()->end()
            ->variableNode('paths')->defaultNull()->end()
            ->variableNode('roles')->defaultNull()->end()
            ->variableNode('extensions')->defaultNull()->end()
            ->variableNode('writable')->defaultNull()->end()
            ->booleanNode('optional')->defaultNull()->end()
            ->booleanNode('if_exists')->defaultNull()->end()
            ->booleanNode('require_tar')->defaultNull()->end()
            ->booleanNode('write_done')->defaultNull()->end()
            ->booleanNode('skip_if_admin_exists')->defaultNull()->end()
            ->scalarNode('when')->defaultNull()->end()
            ->variableNode('when_answer')
                ->info('Map of answer key => required value; step is skipped unless all match.')
                ->defaultNull()
            ->end();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultSetupProfiles(): array
    {
        return [
            'fresh_install' => [
                'steps' => [
                    ['type' => 'requirements'],
                    ['type' => 'bootstrap_mode'],
                    ['type' => 'database_url', 'optional' => true],
                    ['type' => 'database_create'],
                    ['type' => 'cache_clear'],
                    [
                        'type'  => 'sql_file',
                        'id'    => 'full_database_import',
                        'paths' => [
                            'var/site-backup/full-import.sql',
                            'var/site-backup/last-restore-dump.sql',
                        ],
                        'if_exists'   => false,
                        'when_answer' => ['bootstrap_mode' => 'full_database'],
                    ],
                    ['type' => 'migrations'],
                    ['type' => 'admin_user', 'roles' => ['ROLE_SUPER_ADMIN'], 'skip_if_admin_exists' => true],
                    ['type' => 'marker', 'write_done' => true],
                ],
            ],
            'post_restore' => [
                'steps' => [
                    ['type' => 'requirements'],
                    ['type' => 'database_create'],
                    ['type' => 'cache_clear'],
                    ['type' => 'sql_file', 'paths' => ['var/site-backup/last-restore-dump.sql'], 'if_exists' => true],
                    ['type' => 'migrations'],
                    ['type' => 'admin_user', 'skip_if_admin_exists' => true],
                    ['type' => 'marker', 'write_done' => true],
                ],
            ],
            'full_database' => [
                'steps' => [
                    ['type' => 'requirements'],
                    ['type' => 'database_url', 'optional' => true],
                    ['type' => 'database_create'],
                    ['type' => 'cache_clear'],
                    [
                        'type'  => 'sql_file',
                        'id'    => 'full_database_import',
                        'paths' => [
                            'var/site-backup/full-import.sql',
                            'var/site-backup/last-restore-dump.sql',
                        ],
                        'if_exists' => false,
                    ],
                    ['type' => 'migrations'],
                    ['type' => 'admin_user', 'skip_if_admin_exists' => true],
                    ['type' => 'marker', 'write_done' => true],
                ],
            ],
            'minimal' => [
                'steps' => [
                    ['type' => 'database_create'],
                    ['type' => 'migrations'],
                    ['type' => 'admin_user'],
                    ['type' => 'marker', 'write_done' => true],
                ],
            ],
        ];
    }

    /**
     * Same parsing as symfony/rate-limiter ("+<interval>" relative format); must move time forward.
     */
    private static function isValidInterval(string $interval): bool
    {
        $now = new DateTimeImmutable('@0');

        try {
            $then = @$now->modify('+' . $interval);
        } catch (Throwable) {
            return false;
        }

        return $then instanceof DateTimeImmutable && $then > $now;
    }

    private static function isLocaleList(mixed $v): bool
    {
        if (!is_array($v)) {
            return false;
        }
        foreach ($v as $locale) {
            if (!is_string($locale)) {
                return false;
            }
        }

        return true;
    }
}
