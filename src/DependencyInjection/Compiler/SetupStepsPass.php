<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Hands {@see SetupStepFactory} the host setup steps tagged `nowo.site_backup.setup_step` (through
 * `#[AsSetupStep(type: '…')]` or `tags: [{ name: nowo.site_backup.setup_step, type: … }]`), keyed by type (1.35+).
 *
 * A missing or malformed type, a type used twice, or a reserved (built-in) type fails the build: a host step can never
 * replace the admin creation, the `.env.local` rewrite or the SQL import, so the re-opened wizard rules stay intact.
 */
final class SetupStepsPass implements CompilerPassInterface
{
    public const TAG = 'nowo.site_backup.setup_step';

    /** Built-in step types of {@see SetupStepFactory} plus `custom`. */
    public const RESERVED_TYPES = [
        'requirements', 'bootstrap_mode', 'database_url', 'database_create', 'cache_clear', 'schema_update',
        'migrations', 'sql_file', 'console', 'admin_user', 'sample_data', 'marker', 'custom',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SetupStepFactory::class)) {
            return;
        }

        $steps = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            foreach ($tags as $attributes) {
                $type = $attributes['type'] ?? null;
                if (!is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1) {
                    throw new InvalidArgumentException(sprintf('Setup step service "%s" needs a "type" of lower-case letters, digits and "_" (tag %s).', $id, self::TAG));
                }
                if (in_array($type, self::RESERVED_TYPES, true)) {
                    throw new InvalidArgumentException(sprintf('Setup step service "%s" cannot use the reserved type "%s".', $id, $type));
                }
                if (isset($steps[$type])) {
                    throw new InvalidArgumentException(sprintf('Setup step type "%s" is registered twice ("%s" and "%s").', $type, (string) $steps[$type], $id));
                }
                $steps[$type] = new Reference($id);
            }
        }

        $container->getDefinition(SetupStepFactory::class)->setArgument('$customSteps', $steps);
    }
}
