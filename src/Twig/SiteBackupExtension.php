<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Twig;

use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Restore helpers + layout globals (REQ-TWIG-001 / REQ-UI-001).
 */
final class SiteBackupExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_SETUP_LAYOUT_TEMPLATE = 'nowo_site_backup_setup_layout_template';

    public const GLOBAL_PANEL_LAYOUT_TEMPLATE = 'nowo_site_backup_panel_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_site_backup_css_framework';

    public function __construct(
        private readonly SiteBackupManager $manager,
        private readonly string $setupLayoutTemplate = '@NowoSiteBackupBundle/setup/layout.html.twig',
        private readonly string $panelLayoutTemplate = '@NowoSiteBackupBundle/panel/layout.html.twig',
        private readonly string $cssFramework = 'custom',
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
     * @return array<string, string>
     */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_SETUP_LAYOUT_TEMPLATE => $this->setupLayoutTemplate,
            self::GLOBAL_PANEL_LAYOUT_TEMPLATE => $this->panelLayoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK         => $this->cssFramework,
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
}
