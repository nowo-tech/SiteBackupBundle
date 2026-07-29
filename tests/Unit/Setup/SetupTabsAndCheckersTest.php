<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerInterface;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerLocator;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckResult;
use Nowo\SiteBackupBundle\Setup\Step\CustomSetupStep;
use Nowo\SiteBackupBundle\Setup\Step\TabStep;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

final class SetupTabsAndCheckersTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-setup-tabs-' . uniqid('', true);
        $this->fs->mkdir($this->dir . '/var');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testTabCheckResultStatuses(): void
    {
        self::assertTrue(SetupTabCheckResult::ok('setup.check.ok')->isOk());
        self::assertTrue(SetupTabCheckResult::waitingForInput('setup.check.needs_input')->needsInput());
        self::assertTrue(SetupTabCheckResult::blocked('setup.check.blocked')->isBlocked());
        self::assertSame('ok', SetupTabCheckResult::ok()->getStatus());
    }

    public function testCustomStepWaitsUntilContinue(): void
    {
        $step = new CustomSetupStep('menus', 'setup.tab.custom');
        $ctx  = new SetupContext($this->dir, 'fresh_install');
        self::assertTrue($step->run($ctx, new SetupStepInput())->isWaitingForInput());
        self::assertTrue($step->run($ctx, new SetupStepInput(['action' => 'continue']))->isSuccess());
    }

    public function testFactoryWrapsCustomWithCheckerAndTemplate(): void
    {
        $checker = new class implements SetupTabCheckerInterface {
            public function check(SetupContext $ctx): SetupTabCheckResult
            {
                return SetupTabCheckResult::waitingForInput('setup.check.needs_input');
            }
        };
        $locator = new SetupTabCheckerLocator(new class($checker) implements ContainerInterface {
            public function __construct(private object $checker)
            {
            }

            public function get(string $id): mixed
            {
                return $this->checker;
            }

            public function has(string $id): bool
            {
                return $id === 'App\\MenusChecker';
            }
        });

        $markers = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $factory = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
            null,
            [],
            $locator,
        );

        $step = $factory->create([
            'type'         => 'custom',
            'id'           => 'menus',
            'label'        => 'setup.tab.custom',
            'template'     => '@App/setup/menus.html.twig',
            'checker'      => 'App\\MenusChecker',
            'label_domain' => 'messages',
            'description'  => 'setup.tab.custom.help',
        ], 0);

        self::assertInstanceOf(TabStep::class, $step);
        self::assertSame('@App/setup/menus.html.twig', $step->getTemplate());
        self::assertSame('messages', $step->getLabelDomain());
        self::assertSame('setup.tab.custom.help', $step->getDescription());

        $ctx = new SetupContext($this->dir, 'fresh_install');
        $r   = $step->run($ctx, new SetupStepInput());
        self::assertTrue($r->isWaitingForInput());
    }

    public function testManualAdvanceModeRunsOneAutoTab(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );
        $orch = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'two_markers' => [
                    'advance_mode' => 'manual',
                    'steps'        => [
                        ['type' => 'marker', 'id' => 'm1', 'write_done' => false],
                        ['type' => 'marker', 'id' => 'm2', 'write_done' => true],
                    ],
                ],
            ],
            defaultProfile: 'two_markers',
            defaultAdvanceMode: 'automatic',
        );

        self::assertSame('manual', $orch->getAdvanceMode('two_markers'));

        $first = $orch->advance('two_markers');
        self::assertSame(SetupProgress::PHASE_RUNNING, $first->getPhase());
        self::assertContains('m1', $first->getCompletedStepIds());
        self::assertNotContains('m2', $first->getCompletedStepIds());

        $second = $orch->advance('two_markers', new SetupStepInput(), true);
        self::assertSame(SetupProgress::PHASE_COMPLETED, $second->getPhase());
    }

    public function testForceAutomaticIgnoresManualProfile(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );
        $orch = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'two_markers' => [
                    'advance_mode' => 'manual',
                    'steps'        => [
                        ['type' => 'marker', 'id' => 'm1', 'write_done' => false],
                        ['type' => 'marker', 'id' => 'm2', 'write_done' => true],
                    ],
                ],
            ],
            defaultProfile: 'two_markers',
        );

        $result = $orch->advance('two_markers', null, true);
        self::assertSame(SetupProgress::PHASE_COMPLETED, $result->getPhase());
    }

    public function testCheckerOkMarksTabComplete(): void
    {
        $checker = new class implements SetupTabCheckerInterface {
            public function check(SetupContext $ctx): SetupTabCheckResult
            {
                return SetupTabCheckResult::ok();
            }
        };
        $inner = new CustomSetupStep('x', 'setup.tab.custom');
        $tab   = new TabStep($inner, $checker);
        $ctx   = new SetupContext($this->dir, 'p');
        self::assertTrue($tab->isComplete($ctx));
        self::assertTrue($tab->run($ctx, new SetupStepInput())->isSuccess());
    }

    public function testCheckerBlockedFails(): void
    {
        $checker = new class implements SetupTabCheckerInterface {
            public function check(SetupContext $ctx): SetupTabCheckResult
            {
                return SetupTabCheckResult::blocked('setup.check.blocked');
            }
        };
        $tab = new TabStep(new CustomSetupStep('x', 'l'), $checker);
        $r   = $tab->run(new SetupContext($this->dir, 'p'), new SetupStepInput());
        self::assertFalse($r->isSuccess());
        self::assertSame('setup.check.blocked', $r->getMessage());
    }

    public function testDefaultAdvanceModeManualWhenProfileOmits(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $progress = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );
        $orch = new SetupOrchestrator(
            projectDir: $this->dir,
            stepFactory: $factory,
            progressStorage: $progress,
            markers: $markers,
            profiles: [
                'one' => [
                    'steps' => [
                        ['type' => 'marker', 'write_done' => true],
                    ],
                ],
            ],
            defaultProfile: 'one',
            defaultAdvanceMode: 'manual',
        );
        self::assertSame('manual', $orch->getAdvanceMode('one'));
    }

    public function testLocatorReturnsNullForMissing(): void
    {
        $locator = new SetupTabCheckerLocator(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('missing');
            }

            public function has(string $id): bool
            {
                return false;
            }
        });
        self::assertNull($locator->get(null));
        self::assertNull($locator->get('Nope'));
    }

    public function testLocatorIgnoresNonCheckerAndExceptions(): void
    {
        $locator = new SetupTabCheckerLocator(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'boom') {
                    throw new RuntimeException('boom');
                }

                return new stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        });
        self::assertNull($locator->get('not-checker'));
        self::assertNull($locator->get('boom'));
    }

    public function testCustomWithRunnerAndInvalidWhenAnswer(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $factory = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
        );
        $step = $factory->create([
            'type'        => 'custom',
            'id'          => 'sync',
            'label'       => 'setup.tab.custom',
            'runner'      => ['type' => 'console', 'command' => 'cache:clear', 'label' => 'Run sync'],
            'when_answer' => [0 => 'bad', 'bootstrap_mode' => 'guided'],
        ], 0);
        self::assertInstanceOf(TabStep::class, $step);
        self::assertSame('sync', $step->getId());
        self::assertSame('auto', $step->getInner()->getUiKind());
        $ctx = new SetupContext($this->dir, 'p');
        self::assertFalse($step->isEnabled($ctx));
        $ctx->setAnswer('bootstrap_mode', 'guided');
        self::assertTrue($step->isEnabled($ctx));
    }

    public function testBundleRegistersCheckerAutoconfigure(): void
    {
        $container = new ContainerBuilder();
        (new NowoSiteBackupBundle())->build($container);
        $prop = new ReflectionProperty(ContainerBuilder::class, 'autoconfiguredAttributes');
        /** @var array<class-string, list<callable>> $attrs */
        $attrs = $prop->getValue($container);
        self::assertArrayHasKey(AsSetupTabChecker::class, $attrs);
        $defn = new ChildDefinition('abstract');
        $attrs[AsSetupTabChecker::class][0]($defn);
        self::assertTrue($defn->hasTag('nowo.site_backup.setup_tab_checker'));
    }
}
