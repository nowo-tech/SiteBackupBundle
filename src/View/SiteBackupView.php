<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\View;

use InvalidArgumentException;
use Nowo\UiKitBundle\Navigation\AreaNavProviderInterface;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;
use function trigger_deprecation;

/**
 * Renders the panel and setup pages on the UiKit page contract (REQ-UI-003, v1.21+): resolves the per-page template
 * ({@code web_ui.templates.<page>}, legacy {@code templates.*} keys still read), the layouts handed to the view models
 * ({@code web_ui.layout_template} for the panel, {@code web_ui.guest_layout_template} for the login and the setup
 * wizard; null = {@code nowo_ui_kit.layouts.admin} / {@code .guest}), 1.x layouts that do not render the UiKit
 * canonical blocks (wrapped by a deprecated bridge), and the panel area navigation (Backups | History, also published
 * to the UiKit area-nav registry as area {@see self::AREA}).
 *
 * Immutable and request-agnostic (FrankenPHP worker safe); it uses no database service, so the setup wizard renders
 * before the application has a schema or users.
 */
final readonly class SiteBackupView implements AreaNavProviderInterface
{
    public const BUNDLE = 'NowoSiteBackupBundle';

    /** Area code of the panel navigation ({@code nowo_ui_area_nav('site_backup')}). */
    public const AREA = 'site_backup';

    public const DEFAULT_PATH_PREFIX = '/_site_backup';

    /** UiKit guest layout used when the Twig global {@code nowo_ui_layout} is not available. */
    public const UI_KIT_GUEST_LAYOUT = '@NowoUiKit/layout/guest.html.twig';

    /**
     * Deprecated bridges around 1.x layouts that render the bundle content block instead of the UiKit canonical
     * blocks (panel: nowo_site_backup_panel_content, setup: nowo_site_backup_content). Removed in 2.0.
     */
    public const LEGACY_PANEL_BRIDGE = '@NowoSiteBackupBundle/panel/_legacy_layout.html.twig';

    public const LEGACY_GUEST_BRIDGE = '@NowoSiteBackupBundle/setup/_legacy_layout.html.twig';

    /**
     * Page id => default template (override with {@code web_ui.templates.<page>}).
     *
     * @var array<string, string>
     */
    public const TEMPLATES = [
        'index'           => '@NowoSiteBackupBundle/panel/index.html.twig',
        'history'         => '@NowoSiteBackupBundle/panel/history.html.twig',
        'create'          => '@NowoSiteBackupBundle/panel/create.html.twig',
        'restore_confirm' => '@NowoSiteBackupBundle/panel/confirm.html.twig',
        'delete_confirm'  => '@NowoSiteBackupBundle/panel/confirm.html.twig',
        'login'           => '@NowoSiteBackupBundle/panel/login.html.twig',
        'setup_wizard'    => '@NowoSiteBackupBundle/setup/wizard.html.twig',
        'setup_token'     => '@NowoSiteBackupBundle/setup/token.html.twig',
        'setup_done'      => '@NowoSiteBackupBundle/setup/done.html.twig',
        'restore_page'    => '@NowoSiteBackupBundle/restore/page.html.twig',
    ];

    /**
     * Page id => deprecated 1.x {@code templates.*} key (still read for one minor version).
     *
     * @var array<string, string>
     */
    public const LEGACY_TEMPLATE_KEYS = [
        'index'        => 'panel_index',
        'history'      => 'panel_history',
        'login'        => 'panel_login',
        'setup_wizard' => 'setup_wizard',
        'setup_token'  => 'setup_token',
        'setup_done'   => 'setup_done',
    ];

    /** 1.x bundle layouts (removed in 1.21): configured values map to null (UiKit layouts). */
    public const LEGACY_BUNDLE_LAYOUTS = [
        '@NowoSiteBackupBundle/panel/layout.html.twig',
        '@NowoSiteBackupBundle/setup/layout.html.twig',
        '@NowoSiteBackup/panel/layout.html.twig',
        '@NowoSiteBackup/setup/layout.html.twig',
    ];

    public const ROW_ACTIONS_DISPLAYS = ['text', 'icon', 'icon_text'];

    /**
     * @param array<string, string> $templates page id => template (web_ui.templates; legacy templates.* keys accepted)
     * @param string|null $rowActionsDisplay deprecated panel.row_actions_display override (null = nowo_ui_kit.row_actions_display)
     */
    public function __construct(
        private Environment $twig,
        public string $pathPrefix = self::DEFAULT_PATH_PREFIX,
        private ?string $layoutTemplate = null,
        private ?string $guestLayoutTemplate = null,
        private array $templates = [],
        public ?string $rowActionsDisplay = null,
    ) {
    }

    /**
     * Translation key of the bundle domain, translated by the UiKit scaffolds.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::BUNDLE, $params);
    }

    /**
     * Normalises a configured layout: empty values and the removed 1.x bundle layouts mean "UiKit layout" (null).
     */
    public static function normalizeLayout(mixed $layout): ?string
    {
        if (!is_string($layout) || $layout === '' || in_array($layout, self::LEGACY_BUNDLE_LAYOUTS, true)) {
            return null;
        }

        return $layout;
    }

    public function template(string $page): string
    {
        if (!array_key_exists($page, self::TEMPLATES)) {
            throw new InvalidArgumentException(sprintf('Unknown SiteBackup page "%s" (%s).', $page, implode(', ', array_keys(self::TEMPLATES))));
        }

        $legacy = self::LEGACY_TEMPLATE_KEYS[$page] ?? null;

        return $this->templates[$page]
            ?? ($legacy !== null ? ($this->templates[$legacy] ?? null) : null)
            ?? self::TEMPLATES[$page];
    }

    /**
     * Layout of the panel view models: null (UiKit admin layout), the configured layout, or the legacy bridge.
     */
    public function layout(): ?string
    {
        return $this->resolve($this->layoutTemplate, self::LEGACY_PANEL_BRIDGE);
    }

    /**
     * Layout of the guest view models (panel login, setup wizard): the configured guest layout (or its legacy bridge),
     * else the UiKit guest layout ({@code nowo_ui_kit.layouts.guest}). Never null: the wizard scaffold would
     * otherwise extend the admin layout.
     */
    public function guestLayout(): string
    {
        $configured = $this->resolve($this->guestLayoutTemplate, self::LEGACY_GUEST_BRIDGE);
        if ($configured !== null) {
            return $configured;
        }

        $layouts = $this->twig->getGlobals()['nowo_ui_layout'] ?? null;
        $guest   = is_array($layouts) ? ($layouts['guest'] ?? null) : null;

        return is_string($guest) && $guest !== '' ? $guest : self::UI_KIT_GUEST_LAYOUT;
    }

    /**
     * Layout override of the guest view models whose scaffold already defaults to the guest layout ({@code AuthPage}):
     * null = {@code nowo_ui_kit.layouts.guest}.
     */
    public function guestLayoutOverride(): ?string
    {
        return $this->resolve($this->guestLayoutTemplate, self::LEGACY_GUEST_BRIDGE);
    }

    /**
     * Area navigation of the panel (Backups | History) for {@code PageHeader::$tabs}.
     */
    public function nav(string $current): AreaNav
    {
        $prefix = rtrim($this->pathPrefix, '/');

        return new AreaNav([
            new AreaNavItem(self::label('panel.nav.panel'), $prefix === '' ? '/' : $prefix, $current === 'panel', ['data-testid' => 'nowo-sbb-nav-panel']),
            new AreaNavItem(self::label('panel.nav.history'), $prefix . '/history', $current === 'history', ['data-testid' => 'nowo-sbb-nav-history']),
        ], self::label('panel.nav.label'));
    }

    /**
     * The panel navigation for other bundles (DashboardMenu, host menus): area {@see self::AREA}; {@code context.current}
     * (an href) marks that item active.
     *
     * @param array<string, mixed> $context
     */
    public function areaNav(string $area, array $context = []): ?AreaNav
    {
        if ($area !== self::AREA) {
            return null;
        }

        $nav     = $this->nav('');
        $current = $context['current'] ?? null;

        return is_string($current) ? $nav->withActive($current) : $nav;
    }

    /**
     * @param array<string, mixed> $context page context (at least `page`, the view model)
     */
    public function render(string $page, array $context, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->renderView($page, $context), $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function renderView(string $page, array $context): string
    {
        return $this->twig->render($this->template($page), $context + [
            'path_prefix'                  => $this->pathPrefix,
            'nowo_site_backup_legacy'      => $this->legacyContext(),
            'nowo_site_backup_row_actions' => $this->rowActionsDisplay,
        ]);
    }

    /**
     * Variables of the legacy layout bridge: the wrapped 1.x layouts (null when the UiKit contract applies).
     *
     * @return array{panel: string|null, guest: string|null}
     */
    private function legacyContext(): array
    {
        return [
            'panel' => $this->isLegacy($this->layoutTemplate) ? $this->layoutTemplate : null,
            'guest' => $this->isLegacy($this->guestLayoutTemplate) ? $this->guestLayoutTemplate : null,
        ];
    }

    private function resolve(?string $layout, string $bridge): ?string
    {
        $layout = self::normalizeLayout($layout);
        if ($layout === null) {
            return null;
        }

        if ($this->isLegacy($layout)) {
            trigger_deprecation('nowo-tech/site-backup-bundle', '1.21', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_content…); it is wrapped in "%s". Render the canonical blocks in your layout (see docs/UPGRADING.md) or set nowo_site_backup.web_ui.layout_template / guest_layout_template to null.', $layout, $bridge);

            return $bridge;
        }

        return $layout;
    }

    private function isLegacy(?string $layout): bool
    {
        $layout = self::normalizeLayout($layout);

        return $layout !== null && !$this->twig->load($layout)->hasBlock('nowo_ui_content');
    }
}
