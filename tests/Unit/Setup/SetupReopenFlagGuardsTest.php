<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Audit IN-L2 (1.32): `setup.reopen_when_detector_requires` goes through the 1.31 reopen guards.
 */
final class SetupReopenFlagGuardsTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    private SetupMarkerManager $markers;

    private FakeDurableSetupDoneStore $durable;

    private FilesystemSetupProgressStorage $progress;

    protected function setUp(): void
    {
        $this->initHarness();
        $dir           = $this->harnessProjectDir . '/_setup';
        $this->markers = new SetupMarkerManager($dir . '/required', $dir . '/done');
        $this->markers->markDone();
        $this->durable  = new FakeDurableSetupDoneStore(true);
        $this->progress = new FilesystemSetupProgressStorage($dir . '/progress.json');
        $this->progress->save(new SetupProgress(SetupProgress::PHASE_COMPLETED));
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testOnlyAMainGetOrHeadFromTheGateReopens(): void
    {
        $evaluator = $this->evaluator([$this->detector(true)]);
        $gate      = $this->gate($evaluator);

        // Before 1.32 a POST reopened, and so did the wizard / SetupDbDoneGuard / commands (no argument).
        self::assertFalse($evaluator->isSetupRequired());
        self::assertSame([], $evaluator->getReasons());
        foreach ([['POST', '/'], ['PUT', '/x'], ['DELETE', '/x'], ['GET', '/health/live'], ['GET', '/_setup'], ['GET', '/_site_backup/x']] as [$method, $path]) {
            self::assertNull($this->dispatch($gate, Request::create($path, $method)), $method . ' ' . $path);
        }
        $this->assertStillDone();

        self::assertInstanceOf(Response::class, $this->dispatch($gate, Request::create('/', 'GET')));
        self::assertFalse($this->markers->isDone());
        self::assertSame(1, $this->durable->clearCalls);
        self::assertTrue($this->markers->isReopened(), 'IN-I1: the wizard knows it is a reopen');
    }

    public function testAThrowingDetectorCountsAsNotRequiredAndIsLogged(): void
    {
        $throwing = $this->createStub(SetupNeedDetectorInterface::class);
        $throwing->method('isSetupRequired')->willThrowException(new RuntimeException('probe failed'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $exception       = $context['exception'] ?? null;
                $this->records[] = $level . ':' . ($exception instanceof Throwable ? $exception->getMessage() : '');
            }
        };
        $evaluator = $this->evaluator([$throwing, $this->detector(false)], $logger);

        // Before 1.32 the RuntimeException escaped the gate (500 on every request of a done site).
        self::assertNull($this->dispatch($this->gate($evaluator), Request::create('/', 'GET')));
        self::assertSame(['warning:probe failed'], $logger->records);
        $this->assertStillDone();
    }

    public function testDoctrineDetectorsAreNotReopenSignalsOnceDone(): void
    {
        $down = new class {
            public function executeQuery(string $sql): never
            {
                throw new RuntimeException('SQLSTATE[HY000] [1040] Too many connections');
            }
        };
        $connect = new DoctrineConnectDetector($down, true);
        $schema  = new DoctrineSchemaEmptyDetector($down, true);
        self::assertTrue($connect->isSetupRequired(), 'fail-true by design: the cold-start signal');

        $evaluator = $this->evaluator([$connect, $schema]);
        self::assertNull($this->dispatch($this->gate($evaluator), Request::create('/', 'GET')), 'a database blip does not reopen');
        $this->assertStillDone();

        // Not done (cold start): they still open the wizard as before.
        $this->markers->clearDone();
        $this->durable->clearDone();
        self::assertTrue($evaluator->isSetupRequired());
    }

    public function testFlagOffKeepsTheFastPath(): void
    {
        $evaluator = new SetupNeedEvaluator([$this->detector(true)], true, true, $this->markers, $this->durable, reopener: $this->reopener());
        self::assertFalse($evaluator->isSetupRequired(true));
        $this->assertStillDone();
    }

    private function assertStillDone(): void
    {
        self::assertTrue($this->markers->isDone(), 'setup.done kept');
        self::assertSame(0, $this->durable->clearCalls, 'durable store kept');
        self::assertSame(SetupProgress::PHASE_COMPLETED, $this->progress->load()->getPhase(), 'progress kept');
        self::assertFalse($this->markers->isReopened());
    }

    /**
     * @param list<SetupNeedDetectorInterface> $detectors
     */
    private function evaluator(array $detectors, ?AbstractLogger $logger = null): SetupNeedEvaluator
    {
        return new SetupNeedEvaluator(
            $detectors,
            true,
            true,
            $this->markers,
            $this->durable,
            reopenWhenDetectorRequires: true,
            reopener: $this->reopener(),
            logger: $logger,
        );
    }

    private function reopener(): SetupWizardReopener
    {
        return new SetupWizardReopener($this->markers, $this->progress, $this->durable);
    }

    private function gate(SetupNeedEvaluator $evaluator): SetupRequestSubscriber
    {
        return new SetupRequestSubscriber(true, $evaluator, $this->createManager(), new SiteBackupExclusionMatcher([], ['/health/'], [], [], []), '/_setup', '/_site_backup');
    }

    private function detector(bool $required): SetupNeedDetectorInterface
    {
        $detector = $this->createStub(SetupNeedDetectorInterface::class);
        $detector->method('isSetupRequired')->willReturn($required);
        $detector->method('getReason')->willReturn('catalogs empty');

        return $detector;
    }

    private function dispatch(SetupRequestSubscriber $subscriber, Request $request): ?Response
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        return $event->getResponse();
    }
}
