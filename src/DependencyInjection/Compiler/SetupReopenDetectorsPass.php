<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function filter_var;
use function get_debug_type;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function uasort;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * Hands {@see SetupNeedEvaluator} the setup-need detectors tagged `reopen: true` (v1.31+, SB-1): through
 * `#[AsSetupNeedDetector(reopen: true)]` or `tags: [{ name: nowo.site_backup.setup_need_detector, reopen: true }]`.
 * Highest priority first, like the tagged iterator of every detector. `reopen` is normalised as a boolean (XML gives
 * the string "true"; "1", "0", "false", "yes", "no", "on", "off" too); any other value fails the compilation (IN-I6).
 */
final class SetupReopenDetectorsPass implements CompilerPassInterface
{
    public const TAG = 'nowo.site_backup.setup_need_detector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SetupNeedEvaluator::class)) {
            return;
        }

        $priorities = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            foreach ($tags as $attributes) {
                if ($this->reopen($id, $attributes['reopen'] ?? false)) {
                    $priority        = $attributes['priority'] ?? 0;
                    $priorities[$id] = is_numeric($priority) ? (int) $priority : 0;

                    break;
                }
            }
        }
        uasort($priorities, static fn (int $a, int $b): int => $b <=> $a);

        $references = [];
        foreach ($priorities as $id => $priority) {
            $references[] = new Reference($id);
        }

        $container->getDefinition(SetupNeedEvaluator::class)
            ->setArgument('$reopenDetectors', new IteratorArgument($references));
    }

    private function reopen(string $id, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $flag = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($flag === null) {
            throw new InvalidArgumentException(sprintf('Service "%s": the "reopen" attribute of the "%s" tag must be a boolean, %s given.', $id, self::TAG, get_debug_type($value)));
        }

        return $flag;
    }
}
