<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_numeric;
use function uasort;

/**
 * Hands {@see SetupNeedEvaluator} the setup-need detectors tagged `reopen: true` (v1.31+, SB-1): through
 * `#[AsSetupNeedDetector(reopen: true)]` or `tags: [{ name: nowo.site_backup.setup_need_detector, reopen: true }]`.
 * Highest priority first, like the tagged iterator of every detector.
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
                if (($attributes['reopen'] ?? false) === true) {
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
}
