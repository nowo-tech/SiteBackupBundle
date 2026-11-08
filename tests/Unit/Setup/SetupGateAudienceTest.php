<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\SetupReopenDetectorsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
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

    public function testAReopenDetectorReopensTheDoneWizard(): void
    {
        $markers = $this->markers();
        $markers->markDone();
        $quiet     = $this->detector(false, 'quiet');
        $required  = $this->detector(true, 'catalogs empty');
        $evaluator = new SetupNeedEvaluator([$quiet, $required], true, true, $markers, reopener: $this->reopener($markers), reopenDetectors: [$required]);

        self::assertSame(['catalogs empty'], $evaluator->getReasons());
        self::assertFalse($markers->isDone(), 'reopened');

        $markers->markDone();
        self::assertTrue($evaluator->isSetupRequired());
        self::assertFalse($markers->isDone());

        $markers->markDone();
        $none = new SetupNeedEvaluator([$required], true, true, $markers, reopener: $this->reopener($markers));
        self::assertFalse($none->isSetupRequired(), 'without reopen detectors: the 1.30 fast path');
        self::assertSame([], $none->getReasons());
        self::assertTrue($markers->isDone());

        $calm = new SetupNeedEvaluator([$quiet], true, true, $markers, reopener: $this->reopener($markers), reopenDetectors: [$quiet]);
        self::assertFalse($calm->isSetupRequired());
        self::assertSame([], $calm->getReasons());
        self::assertTrue($markers->isDone());
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
        $container->register('loose')->addTag(SetupReopenDetectorsPass::TAG, ['reopen' => 'yes']);
        (new SetupReopenDetectorsPass())->process($container);

        $argument = $container->getDefinition(SetupNeedEvaluator::class)->getArgument('$reopenDetectors');
        self::assertInstanceOf(IteratorArgument::class, $argument);
        self::assertSame(['high', 'low', 'odd'], array_map(static fn (Reference $ref): string => (string) $ref, $argument->getValues()));
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
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/dashboard'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        return $event->getResponse() instanceof Response;
    }
}
