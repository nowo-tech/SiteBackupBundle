<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\View;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\PageFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use Nowo\SiteBackupBundle\View\PanelPages;
use Nowo\SiteBackupBundle\View\SetupPages;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * REQ-UI-003 view-model builders, the page renderer and the panel.extra_classes → UiKit theme bridge.
 */
final class ViewModelsTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    public function testTemplatesResolveCanonicalThenLegacyKeys(): void
    {
        $twig = new Environment(new ArrayLoader());
        $view = new SiteBackupView($twig, templates: ['panel_index' => 'legacy/index.html.twig', 'history' => 'new/history.html.twig', 'panel_history' => 'ignored.html.twig']);

        self::assertSame('legacy/index.html.twig', $view->template('index'));
        self::assertSame('new/history.html.twig', $view->template('history'));
        self::assertSame(SiteBackupView::TEMPLATES['delete_confirm'], $view->template('delete_confirm'));
        self::assertSame(SiteBackupView::UI_KIT_GUEST_LAYOUT, $view->guestLayout(), 'Without the UiKit global the UiKit guest layout applies.');

        $this->expectException(InvalidArgumentException::class);
        $view->template('dashboard');
    }

    public function testPanelPagesSkipArchivesWithoutForms(): void
    {
        $page = (new PanelPages(PageFixtures::view()))->index(PageFixtures::backups(), [], null);

        self::assertSame([], $page->rowActions(PageFixtures::backups()[0], 0));
        self::assertCount(1, $page->header->actions, 'No logout action without its form.');
    }

    public function testSetupPagesIgnoreForeignStepsAndFinishOnDone(): void
    {
        $steps    = [new stdClass(), ...PageFixtures::steps()];
        $progress = new SetupProgress(SetupProgress::PHASE_RUNNING, completedStepIds: ['requirements', 'bootstrap_mode', 'database_url', 'admin_user']);
        $page     = (new SetupPages(PageFixtures::view()))->wizard($steps, $progress, null, null);

        self::assertCount(5, $page->steps);
        self::assertSame(SetupPages::DONE_STEP, $page->current);
        self::assertTrue($page->guest, 'UiKit 2.2 WizardPage::$guest: the wizard renders on nowo_ui_kit.layouts.guest.');
        self::assertNull($page->layout, 'No layout override without web_ui.guest_layout_template.');

        $done = (new SetupPages(PageFixtures::view()))->done(PageFixtures::steps());
        self::assertTrue($done->guest);
        self::assertNull($done->layout);
    }

    public function testExtraClassesBridgeIntoTheUiKitTheme(): void
    {
        self::assertSame([], SiteBackupExtension::themeBridge(['card' => ' ', 'table' => null]));
        self::assertSame(
            ['card' => 'shadow-sm', 'btn_primary' => 'btn-lg', 'btn_secondary' => 'btn-lg', 'action' => 'btn-lg'],
            SiteBackupExtension::themeBridge(['card' => 'shadow-sm', 'button' => 'btn-lg', 'input' => '']),
        );

        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_ui_kit';
            }
        });
        $container->registerExtension(new SiteBackupExtension());
        $container->loadFromExtension('nowo_site_backup', ['panel' => ['extra_classes' => ['table' => 'data-table']]]);
        (new SiteBackupExtension())->prepend($container);

        self::assertSame([['theme' => ['bundles' => ['NowoSiteBackupBundle' => ['classes' => ['table' => 'data-table']]]]]], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPagesBehindTheGateDenyWithoutRole(): void
    {
        $this->initHarness();
        try {
            $panel = new SiteBackupPanelController($this->createManager(), new PasswordSiteBackupAccessGate(null, false), PageFixtures::formFactory(), new Environment(new ArrayLoader()), [], '/_site_backup', allowUnauthenticated: false);

            self::assertSame(403, $panel->history(Request::create('/history'))->getStatusCode());
            self::assertSame(403, $panel->create(Request::create('/create'))->getStatusCode());
        } finally {
            $this->destroyHarness();
        }
    }
}
