<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Twig;

use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function array_merge;
use function is_string;
use function trim;

/**
 * Restore helpers + layout globals (REQ-TWIG-001 / REQ-UI-001).
 */
final class SiteBackupExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_SETUP_LAYOUT_TEMPLATE = 'nowo_site_backup_setup_layout_template';

    public const GLOBAL_PANEL_LAYOUT_TEMPLATE = 'nowo_site_backup_panel_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_site_backup_css_framework';

    /** v1.18+: panel.row_actions_display (text | icon | icon_text | kit). */
    public const GLOBAL_ROW_ACTIONS_DISPLAY = 'nowo_site_backup_row_actions_display';

    /** v1.18+: panel.extra_classes ({card, table, button, input}). */
    public const GLOBAL_PANEL_CLASSES = 'nowo_site_backup_panel_classes';

    private const PANEL_CLASS_SLOTS = ['card' => '', 'table' => '', 'button' => '', 'input' => ''];

    /**
     * @param array<string, mixed> $panelClasses
     */
    public function __construct(
        private readonly SiteBackupManager $manager,
        private readonly string $setupLayoutTemplate = '@NowoSiteBackupBundle/setup/layout.html.twig',
        private readonly string $panelLayoutTemplate = '@NowoSiteBackupBundle/panel/layout.html.twig',
        private readonly string $cssFramework = 'custom',
        private readonly string $rowActionsDisplay = 'text',
        private readonly array $panelClasses = [],
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_site_backup_is_restoring', $this->isRestoring(...)),
            new TwigFunction('nowo_site_backup_progress', $this->progress(...)),
        ];
    }

    /**
     * @return array<string, array<string, string>|string>
     */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_SETUP_LAYOUT_TEMPLATE => $this->setupLayoutTemplate,
            self::GLOBAL_PANEL_LAYOUT_TEMPLATE => $this->panelLayoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK         => $this->cssFramework,
            self::GLOBAL_ROW_ACTIONS_DISPLAY   => $this->rowActionsDisplay,
            self::GLOBAL_PANEL_CLASSES         => $this->panelClasses(),
        ];
    }

    public function isRestoring(): bool
    {
        return $this->manager->isRestoreActive();
    }

    public function progress(): RestoreProgress
    {
        return $this->manager->getRestoreProgress();
    }

    /**
     * @return array<string, string>
     */
    private function panelClasses(): array
    {
        $classes = self::PANEL_CLASS_SLOTS;
        foreach (array_merge(self::PANEL_CLASS_SLOTS, $this->panelClasses) as $slot => $value) {
            $classes[(string) $slot] = is_string($value) ? trim($value) : '';
        }

        return $classes;
    }
}
