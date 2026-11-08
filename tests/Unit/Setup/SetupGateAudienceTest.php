<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\SetupReopenDetectorsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use RuntimeException;
use Stringable;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Throwable;

use function in_array;

/**
 * v1.31 (SB-1): setup.gate audience (all | guests_and_admins) and the per-detector `reopen: true` tag attribute.
 */
final class SetupGateAudienceTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    // --- setup.gate ---------------------------------------------------------------------------------------------

    public function testAllIsTheDefaultAndSendsSignedInMembersToo(): void
    {
        $member = $this->checker(authenticated: true, admin: false);
        self::assertTrue($this->redirects($this->subscriber('all', $member)), 'all ignores the user');
        self::assertTrue($this->redirects($this->subscriber(null, $member)), 'default = all');
    }

    public function testGuestsAndAdminsLetsSignedInMembersThrough(): void
    {
        self::assertFalse($this->redirects($this->subscriber('guests_and_admins', $this->checker(true, false))), 'member');
        self::assertTrue($this->redirects($this->subscriber('guests_and_admins', $this->checker(true, true))), 'admin');
        self::assertTrue($this->redirects($this->subscriber('guests_and_admins', $this->checker(false, false))), 'guest');
        self::assertTrue($this->redirects($this->subscriber('guests_and_admins', null)), 'no security: everyone is a guest');

        $broken = $this->createStub(AuthorizationCheckerInterface::class);
        $broken->method('isGranted')->willThrowException(new RuntimeException('no token storage'));
        self::assertTrue($this->redirects($this->subscriber('guests_and_admins', $broken)), 'a failing check keeps the gate closed');
    }

    public function testTheAdminRoleIsConfigurable(): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, ['IS_AUTHENTICATED_REMEMBERED', 'ROLE_OPS'], true));

        self::assertTrue($this->redirects($this->subscriber('guests_and_admins', $checker, 'ROLE_OPS')));
        self::assertFalse($this->redirects($this->subscriber('guests_and_admins', $checker)), 'ROLE_ADMIN by default');
    }

    public function testExtensionWiresTheGateAfterTheFirewallOnlyForGuestsAndAdmins(): void
    {
        $default = $this->load([]);
        $sub     = $default->getDefinition(SetupRequestSubscriber::class);
        self::assertSame(30, $sub->getTag('kernel.event_listener')[0]['priority']);
        self::assertArrayNotHasKey('$gate', $sub->getArguments(), 'all: the 1.30 definition');

        $gated = $this->load(['gate' => 'guests_and_admins', 'gate_admin_role' => 'ROLE_SUPER_ADMIN'])->getDefinition(SetupRequestSubscriber::class);
        self::assertSame(SiteBackupExtension::SETUP_GATE_AFTER_FIREWALL_PRIORITY, $gated->getTag('kernel.event_listener')[0]['priority']);
        self::assertSame('guests_and_admins', $gated->getArgument('$gate'));
        self::assertSame('ROLE_SUPER_ADMIN', $gated->getArgument('$gateAdminRole'));
        $checker = $gated->getArgument('$authorizationChecker');
        self::assertInstanceOf(Reference::class, $checker);
        self::assertSame('security.authorization_checker', (string) $checker);
        self::assertSame(ContainerBuilder::IGNORE_ON_INVALID_REFERENCE, $checker->getInvalidBehavior());
    }

    // --- reopen: true -------------------------------------------------------------------------------------------

    public function testAReopenDetectorReopensTheDoneWizardOnlyWhenTheCallerAllowsIt(): void
    {
        $markers = $this->markers();
        $markers->markDone();
        $quiet     = $this->detector(false, 'quiet');
        $required  = $this->detector(true, 'catalogs empty');
        $evaluator = new SetupNeedEvaluator([$quiet, $required], true, true, $markers, reopener: $this->reopener($markers), reopenDetectors: [$required]);

        self::assertFalse($evaluator->isSetupRequired(), 'wizard, panel, commands, done guard: never reopen');
        self::assertSame([], $evaluator->getReasons());
        self::assertTrue($markers->isDone());

        self::assertTrue($evaluator->isSetupRequired(true), 'the site gate');
        self::assertFalse($markers->isDone(), 'reopened');
        self::assertSame(['catalogs empty'], $evaluator->getReasons(), 'not done any more: every detector');

        $markers->markDone();
        $none = new SetupNeedEvaluator([$required], true, true, $markers, reopener: $this->reopener($markers));
        self::assertFalse($none->isSetupRequired(true), 'without reopen detectors: the 1.30 fast path');
        self::assertTrue($markers->isDone());

        $calm = new SetupNeedEvaluator([$quiet], true, true, $markers, reopener: $this->reopener($markers), reopenDetectors: [$quiet]);
        self::assertFalse($calm->isSetupRequired(true));
        self::assertTrue($markers->isDone());
    }

    /**
     * Audit IN PoC P1: a reopen detector that reports a need (a fail-true detector during a database blip) must not
     * clear setup.done, the durable store or progress from an excluded path, the setup / panel paths or a POST.
     */
    public function testP1ExcludedPathsAndUnsafeMethodsNeverReopen(): void
    {
        $markers = $this->markers();
        $markers->markDone();
        $durable  = new FakeDurableSetupDoneStore(true);
        $progress = new FilesystemSetupProgressStorage($this->harnessProjectDir . '/_setup/progress.json');
        $progress->save(new SetupProgress(SetupProgress::PHASE_COMPLETED));
        $detector  = $this->detector(true, 'catalogs empty');
        $evaluator = new SetupNeedEvaluator([$detector], true, true, $markers, $durable, reopener: new SetupWizardReopener($markers, $progress, $durable), reopenDetectors: [$detector]);
        $gate      = new SetupRequestSubscriber(true, $evaluator, $this->createManager(), new SiteBackupExclusionMatcher([], ['/health/'], [], [], []), '/_setup', '/_site_backup');

        foreach ([['GET', '/health/live'], ['GET', '/_setup'], ['GET', '/_site_backup/x'], ['POST', '/'], ['PUT', '/api/x']] as [$method, $path]) {
            self::assertNull($this->dispatch($gate, Request::create($path, $method)), $method . ' ' . $path);
            self::assertTrue($markers->isDone(), $method . ' ' . $path . ': setup.done kept');
            self::assertSame(0, $durable->clearCalls, $method . ' ' . $path . ': durable store kept');
            self::assertSame(SetupProgress::PHASE_COMPLETED, $progress->load()->getPhase(), $method . ' ' . $path . ': progress kept');
        }

        // A navigational GET / HEAD is what may reopen (the documented behaviour).
        self::assertInstanceOf(Response::class, $this->dispatch($gate, Request::create('/', 'HEAD')));
        self::assertFalse($markers->isDone());
        self::assertSame(1, $durable->clearCalls);
    }

    /**
     * Audit IN PoC P2: a reopen detector that throws counts as "not required": logged, no reopen, no 500 on any path.
     */
    public function testP2AThrowingReopenDetectorNeitherReopensNorFails(): void
    {
        $markers = $this->markers();
        $markers->markDone();
        $durable  = new FakeDurableSetupDoneStore(true);
        $detector = $this->createStub(SetupNeedDetectorInterface::class);
        $detector->method('isSetupRequired')->willThrowException(new RuntimeException('probe failed'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $exception       = $context['exception'] ?? null;
                $this->records[] = $level . ':' . ($exception instanceof Throwable ? $exception->getMessage() : '');
            }
        };
        $evaluator = new SetupNeedEvaluator([$detector], true, true, $markers, $durable, reopener: $this->reopener($markers), reopenDetectors: [$detector], logger: $logger);
        $gate      = new SetupRequestSubscriber(true, $evaluator, $this->createManager(), new SiteBackupExclusionMatcher([], ['/health/'], [], [], []), '/_setup', '/_site_backup');

        foreach (['/', '/health/live', '/_setup', '/_site_backup/x'] as $path) {
            self::assertNull($this->dispatch($gate, Request::create($path)), $path);
        }
        self::assertTrue($markers->isDone());
        self::assertSame(0, $durable->clearCalls);
        self::assertSame(['warning:probe failed'], $logger->records, 'only "/" reached the detector');

        // Without a logger the failure is still contained.
        $quiet = new SetupNeedEvaluator([$detector], true, true, $markers, reopener: $this->reopener($markers), reopenDetectors: [$detector]);
        self::assertFalse($quiet->isSetupRequired(true));
    }

    public function testTheFirewallsLazyResponseIsKept(): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willThrowException(new LazyResponseException(new Response('', 401)));

        $this->expectException(LazyResponseException::class);
        $this->redirects($this->subscriber('guests_and_admins', $checker));
    }

    public function testAttributeTagsReopenOnlyWhenAsked(): void
    {
        $container = new ContainerBuilder();
        (new NowoSiteBackupBundle())->build($container);
        $callback = ($container->getAttributeAutoconfigurators()[AsSetupNeedDetector::class] ?? [])[0];

        $plain = new ChildDefinition('abstract');
        $callback($plain, new AsSetupNeedDetector(priority: 5), new ReflectionClass(AsSetupNeedDetector::class));
        self::assertSame([['priority' => 5]], $plain->getTag('nowo.site_backup.setup_need_detector'));

        $reopen = new ChildDefinition('abstract');
        $callback($reopen, new AsSetupNeedDetector(priority: 5, reopen: true), new ReflectionClass(AsSetupNeedDetector::class));
        self::assertSame([['priority' => 5, 'reopen' => true]], $reopen->getTag('nowo.site_backup.setup_need_detector'));

        self::assertContains(SetupReopenDetectorsPass::class, array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getPasses()));
    }

    public function testPassCollectsReopenDetectorsByPriority(): void
    {
        $container = new ContainerBuilder();
        (new SetupReopenDetectorsPass())->process($container);
        self::assertFalse($container->hasDefinition(SetupNeedEvaluator::class), 'no evaluator: nothing to do');

        $container->setDefinition(SetupNeedEvaluator::class, new Definition(SetupNeedEvaluator::class));
        $container->register('low')->addTag(SetupReopenDetectorsPass::TAG, ['priority' => 1, 'reopen' => true]);
        $container->register('high')->addTag(SetupReopenDetectorsPass::TAG, ['priority' => 50, 'reopen' => true]);
        $container->register('odd')->addTag(SetupReopenDetectorsPass::TAG, ['priority' => 'x', 'reopen' => true]);
        $container->register('plain')->addTag(SetupReopenDetectorsPass::TAG, ['priority' => 99]);
        $container->register('xml')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => 'true']);
        $container->register('xml-off')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => 'false']);
        $container->register('int')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => 1]);
        (new SetupReopenDetectorsPass())->process($container);

        $argument = $container->getDefinition(SetupNeedEvaluator::class)->getArgument('$reopenDetectors');
        self::assertInstanceOf(IteratorArgument::class, $argument);
        self::assertSame(['high', 'low', 'odd', 'xml', 'int'], array_map(static fn (Reference $ref): string => (string) $ref, $argument->getValues()));
    }

    public function testPassRejectsANonBooleanReopen(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SetupNeedEvaluator::class, new Definition(SetupNeedEvaluator::class));
        $container->register('typo')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => 'maybe']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service "typo": the "reopen" attribute of the "nowo.site_backup.setup_need_detector" tag must be a boolean, string given.');
        (new SetupReopenDetectorsPass())->process($container);
    }

    public function testPassRejectsAnArrayReopen(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SetupNeedEvaluator::class, new Definition(SetupNeedEvaluator::class));
        $container->register('list')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => [true]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array given');
        (new SetupReopenDetectorsPass())->process($container);
    }

    /**
     * @param array<string, mixed> $setup
     */
    private function load(array $setup): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->harnessProjectDir);
        (new SiteBackupExtension())->load([['enabled' => true, 'security' => ['allow_unauthenticated' => true], 'setup' => $setup]], $container);

        return $container;
    }

    private function markers(): SetupMarkerManager
    {
        $dir = $this->harnessProjectDir . '/_setup';

        return new SetupMarkerManager($dir . '/required', $dir . '/done');
    }

    private function reopener(SetupMarkerManager $markers): SetupWizardReopener
    {
        return new SetupWizardReopener($markers, new FilesystemSetupProgressStorage($this->harnessProjectDir . '/_setup/progress.json'));
    }

    private function detector(bool $required, string $reason): SetupNeedDetectorInterface
    {
        $detector = $this->createStub(SetupNeedDetectorInterface::class);
        $detector->method('isSetupRequired')->willReturn($required);
        $detector->method('getReason')->willReturn($reason);

        return $detector;
    }

    private function checker(bool $authenticated, bool $admin): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => $attribute === 'IS_AUTHENTICATED_REMEMBERED' ? $authenticated : $admin);

        return $checker;
    }

    private function subscriber(?string $gate, ?AuthorizationCheckerInterface $checker, string $adminRole = 'ROLE_ADMIN'): SetupRequestSubscriber
    {
        $required = new SetupNeedEvaluator([$this->detector(true, 'empty')], true);
        $matcher  = new SiteBackupExclusionMatcher([], [], [], [], []);
        if ($gate === null) {
            return new SetupRequestSubscriber(true, $required, $this->createManager(), $matcher);
        }

        return new SetupRequestSubscriber(true, $required, $this->createManager(), $matcher, gate: $gate, authorizationChecker: $checker, gateAdminRole: $adminRole);
    }

    private function redirects(SetupRequestSubscriber $subscriber): bool
    {
        return $this->dispatch($subscriber, Request::create('/dashboard')) instanceof Response;
    }

    private function dispatch(SetupRequestSubscriber $subscriber, Request $request): ?Response
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        return $event->getResponse();
    }
}
