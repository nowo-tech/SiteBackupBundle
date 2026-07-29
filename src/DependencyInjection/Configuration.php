<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration tree under alias `nowo_site_backup`.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_site_backup';

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
                    ->addDefaultsIfNotSet()
                    ->children()
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
                        ->arrayNode('profiles')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->arrayNode('steps')
                                        ->arrayPrototype()
                                            ->ignoreExtraKeys(false)
                                            ->children()
                                                ->scalarNode('type')->isRequired()->end()
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
                                            ->end()
                                        ->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                'fresh_install' => [
                                    'steps' => [
                                        ['type' => 'requirements'],
                                        ['type' => 'database_url', 'optional' => true],
                                        ['type' => 'database_create'],
                                        ['type' => 'cache_clear'],
                                        ['type' => 'migrations'],
                                        ['type' => 'admin_user', 'roles' => ['ROLE_SUPER_ADMIN']],
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
                                'minimal' => [
                                    'steps' => [
                                        ['type' => 'database_create'],
                                        ['type' => 'migrations'],
                                        ['type' => 'admin_user'],
                                        ['type' => 'marker', 'write_done' => true],
                                    ],
                                ],
                            ])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
