<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_map;
use function is_array;
use function iterator_to_array;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-SF-003: on a real kernel the attribute-wired services are registered — #[AsEventListener] (time-limit
 * listener, priority 512), #[AsController] (controllers as services) and #[AsSetupNeedDetector] (built-in detectors).
 */
final class AttributeWiringKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_wiring_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testAttributeWiredServicesAreRegistered(): void
    {
        $kernel = new LocaleTestKernel($this->baseDir, ['en', 'es']);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);

            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $timeLimitPriority = null;
            foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
                if (is_array($listener) && $listener[0] instanceof LongRequestTimeLimitSubscriber) {
                    $timeLimitPriority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
                }
            }
            self::assertSame(512, $timeLimitPriority);

            foreach ([SiteBackupPanelController::class, SetupWizardController::class, SetupUnlocalizedLocaleRedirectController::class] as $controller) {
                self::assertTrue($container->has($controller), $controller);
            }

            $evaluator = $container->get(SetupNeedEvaluator::class);
            self::assertInstanceOf(SetupNeedEvaluator::class, $evaluator);
            $detectors = (new ReflectionProperty(SetupNeedEvaluator::class, 'detectors'))->getValue($evaluator);
            self::assertIsIterable($detectors);
            self::assertSame(
                ['MarkerFileDetector', 'DoctrineConnectDetector', 'DoctrineSchemaEmptyDetector', 'IncompleteSetupProgressDetector'],
                array_map(static fn (object $d): string => (new ReflectionClass($d))->getShortName(), iterator_to_array($detectors, false)),
            );
        } finally {
            $kernel->shutdown();
        }
    }
}
