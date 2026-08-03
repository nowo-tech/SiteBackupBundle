<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function in_array;

/**
 * Bundle configuration tree under alias `nowo_site_backup`.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_site_backup';

    /** @var list<string> */
    public const CSS_FRAMEWORKS = ['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->info('Nowo Site Backup Bundle configuration.')
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch: when false the restore loading page is never shown.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('default_message')
                    ->info('Translation id (domain NowoSiteBackupBundle) or literal for the public restore loading page. Default: restore.page.message.')
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
                ->enumNode('css_framework')
                    ->info('Host CSS stack hint for setup/panel Web UI (REQ-UI-001). Twig global nowo_site_backup_css_framework. Demo default: custom (semantic nowo-ui-*).')
                    ->values(self::CSS_FRAMEWORKS)
                    ->defaultValue('custom')
                ->end()
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
                            ->info('Shell command that writes SQL to stdout (e.g. mysqldump …). Empty = no DB dump.')
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
                    ->end()
                ->end()
                ->arrayNode('panel')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('path_prefix')->defaultValue('/_site_backup')->cannotBeEmpty()->end()
                        ->scalarNode('layout_template')
                            ->info('Host Twig layout for panel pages (global nowo_site_backup_panel_layout_template). Must define block nowo_ui_content / nowo_site_backup_panel_content. Default: bundle standalone panel layout.')
                            ->defaultNull()
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
                            ->info('Symfony roles granted access to the panel (at least one). Empty = no bundle-level role check.')
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
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('restore_page')
                            ->defaultValue('@NowoSiteBackupBundle/restore/page.html.twig')
                        ->end()
                        ->scalarNode('panel_layout')
                            ->defaultValue('@NowoSiteBackupBundle/panel/layout.html.twig')
                        ->end()
                        ->scalarNode('panel_index')
                            ->defaultValue('@NowoSiteBackupBundle/panel/index.html.twig')
                        ->end()
                        ->scalarNode('panel_login')
                            ->defaultValue('@NowoSiteBackupBundle/panel/login.html.twig')
                        ->end()
                        ->scalarNode('panel_history')
                            ->defaultValue('@NowoSiteBackupBundle/panel/history.html.twig')
                        ->end()
                        ->scalarNode('setup_wizard')
                            ->defaultValue('@NowoSiteBackupBundle/setup/wizard.html.twig')
                        ->end()
                        ->scalarNode('setup_layout')
                            ->info('Twig layout extended by setup wizard/done/token. Prefer setup.layout_template.')
                            ->defaultValue('@NowoSiteBackupBundle/setup/layout.html.twig')
                        ->end()
                        ->scalarNode('setup_admin')
                            ->defaultValue('@NowoSiteBackupBundle/setup/admin.html.twig')
                        ->end()
                        ->scalarNode('setup_sample')
                            ->defaultValue('@NowoSiteBackupBundle/setup/sample_data.html.twig')
                        ->end()
                        ->scalarNode('setup_database')
                            ->defaultValue('@NowoSiteBackupBundle/setup/database.html.twig')
                        ->end()
                        ->scalarNode('setup_done')
                            ->defaultValue('@NowoSiteBackupBundle/setup/done.html.twig')
                        ->end()
                        ->scalarNode('setup_token')
                            ->defaultValue('@NowoSiteBackupBundle/setup/token.html.twig')
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
                            ->info('Host Twig layout for setup pages (extends pattern like CookieConsent layout_template). Must define block nowo_site_backup_content. Default uses the bundle standalone layout.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('require_done_marker')
                            ->info('When true, missing setup.done forces the wizard (fresh clones). Default false so adding the bundle does not lock existing apps.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('brand_name')->defaultValue('Site Setup')->end()
                        ->scalarNode('setup_token')
                            ->info('Optional shared secret for /_setup (?token= or X-Setup-Token).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('progress_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup-progress.json')
                        ->end()
                        ->enumNode('progress_storage')
                            ->values(['filesystem', 'doctrine', 'chain'])
                            ->info('filesystem = JSON in var/; doctrine = DBAL table; chain = write both, prefer DB on load (survives var/ wipe).')
                            ->defaultValue('filesystem')
                        ->end()
                        ->scalarNode('progress_table')
                            ->info('DBAL table name when progress_storage is doctrine or chain.')
                            ->defaultValue('nowo_site_backup_setup_progress')
                        ->end()
                        ->scalarNode('required_marker_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup.required')
                        ->end()
                        ->scalarNode('done_marker_file')
                            ->defaultValue('%kernel.project_dir%/var/site-backup/setup.done')
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
                                ->arrayNode('enabled')
                                    ->info('List of enabled locale codes.')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['en'])
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
}
