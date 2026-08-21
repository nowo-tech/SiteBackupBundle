<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Detector;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\IncompleteSetupProgressDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Setup\FakeDurableSetupDoneStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

final class DetectorsTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-detect-' . uniqid('', true);
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testMarkerFileDetector(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $detector = new MarkerFileDetector($markers, requireDoneMarker: true, enabled: true);

        self::assertTrue($detector->isSetupRequired());
        self::assertSame('setup.done marker missing', $detector->getReason());

        $markers->markDone();
        self::assertFalse($detector->isSetupRequired());

        $markers->markRequired('post_restore');
        self::assertTrue($detector->isSetupRequired());
        self::assertSame('setup.required marker present', $detector->getReason());

        $disabled = new MarkerFileDetector($markers, enabled: false);
        self::assertFalse($disabled->isSetupRequired());
    }

    public function testSetupNeedEvaluator(): void
    {
        $yes = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return true;
            }

            public function getReason(): string
            {
                return 'yes';
            }
        };
        $no = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return false;
            }

            public function getReason(): string
            {
                return 'no';
            }
        };

        $evaluator = new SetupNeedEvaluator([$no, $yes], setupEnabled: true);
        self::assertTrue($evaluator->isSetupRequired());
        self::assertSame(['yes'], $evaluator->getReasons());

        $disabled = new SetupNeedEvaluator([], setupEnabled: false);
        self::assertFalse($disabled->isSetupRequired());
        self::assertSame([], $disabled->getReasons());
    }

    public function testSetupNeedEvaluatorShortCircuitsWhenMarkerDone(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/sc-req', $this->dir . '/sc-done');
        $markers->markDone();

        $calls     = 0;
        $expensive = new class($calls) implements SetupNeedDetectorInterface {
            public function __construct(private int &$calls)
            {
            }

            public function isSetupRequired(): bool
            {
                ++$this->calls;

                return true;
            }

            public function getReason(): string
            {
                return 'expensive';
            }
        };

        $evaluator = new SetupNeedEvaluator(
            [$expensive],
            setupEnabled: true,
            shortCircuitWhenDone: true,
            markers: $markers,
        );

        self::assertFalse($evaluator->isSetupRequired());
        self::assertSame([], $evaluator->getReasons());
        self::assertSame(0, $calls);
    }

    public function testSetupNeedEvaluatorShortCircuitsWhenDurableDone(): void
    {
        $calls     = 0;
        $expensive = new class($calls) implements SetupNeedDetectorInterface {
            public function __construct(private int &$calls)
            {
            }

            public function isSetupRequired(): bool
            {
                ++$this->calls;

                return true;
            }

            public function getReason(): string
            {
                return 'expensive';
            }
        };

        $evaluator = new SetupNeedEvaluator(
            [$expensive],
            setupEnabled: true,
            shortCircuitWhenDone: true,
            durableDoneStore: new FakeDurableSetupDoneStore(true),
        );

        self::assertFalse($evaluator->isSetupRequired());
        self::assertSame(0, $calls);
    }

    public function testSetupNeedEvaluatorOptOutRunsDetectorsEvenWhenDone(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/sc2-req', $this->dir . '/sc2-done');
        $markers->markDone();

        $yes = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return true;
            }

            public function getReason(): string
            {
                return 'still-needed';
            }
        };

        $evaluator = new SetupNeedEvaluator(
            [$yes],
            setupEnabled: true,
            shortCircuitWhenDone: false,
            markers: $markers,
        );

        self::assertTrue($evaluator->isSetupRequired());
        self::assertSame(['still-needed'], $evaluator->getReasons());
    }

    public function testSetupNeedEvaluatorDurableThrowDoesNotShortCircuit(): void
    {
        $broken = new class implements DurableSetupDoneStoreInterface {
            public function isDone(): bool
            {
                throw new RuntimeException('db down');
            }

            public function markDone(): void
            {
            }
        };

        $yes = new class implements SetupNeedDetectorInterface {
            public function isSetupRequired(): bool
            {
                return true;
            }

            public function getReason(): string
            {
                return 'yes';
            }
        };

        $evaluator = new SetupNeedEvaluator(
            [$yes],
            setupEnabled: true,
            shortCircuitWhenDone: true,
            durableDoneStore: $broken,
        );

        self::assertTrue($evaluator->isSetupRequired());
    }

    public function testDoctrineConnectDetector(): void
    {
        $ok = new class {
            public function executeQuery(string $sql): void
            {
            }
        };
        self::assertFalse((new DoctrineConnectDetector($ok, true))->isSetupRequired());

        $fail = new class {
            public function executeQuery(string $sql): never
            {
                throw new RuntimeException('fail');
            }
        };
        $detector = new DoctrineConnectDetector($fail, true);
        self::assertTrue($detector->isSetupRequired());
        self::assertSame('database connection failed', $detector->getReason());

        $connect = new class {
            public function connect(): void
            {
            }
        };
        self::assertFalse((new DoctrineConnectDetector($connect, true))->isSetupRequired());
        self::assertFalse((new DoctrineConnectDetector(null, true))->isSetupRequired());
    }

    public function testDoctrineSchemaEmptyDetector(): void
    {
        $emptyManager = new class {
            /** @return list<string> */
            public function listTableNames(): array
            {
                return [];
            }
        };
        $connEmpty = new class($emptyManager) {
            public function __construct(private object $manager)
            {
            }

            public function createSchemaManager(): object
            {
                return $this->manager;
            }
        };
        $detector = new DoctrineSchemaEmptyDetector($connEmpty, true);
        self::assertTrue($detector->isSetupRequired());
        self::assertSame('database schema is empty', $detector->getReason());

        $fullManager = new class {
            /** @return list<string> */
            public function listTableNames(): array
            {
                return ['user'];
            }
        };
        $connFull = new class($fullManager) {
            public function __construct(private object $manager)
            {
            }

            public function getSchemaManager(): object
            {
                return $this->manager;
            }
        };
        self::assertFalse((new DoctrineSchemaEmptyDetector($connFull, true))->isSetupRequired());

        $broken = new class {
            public function createSchemaManager(): object
            {
                throw new RuntimeException('fail');
            }
        };
        self::assertFalse((new DoctrineSchemaEmptyDetector($broken, true))->isSetupRequired());
        self::assertFalse((new DoctrineSchemaEmptyDetector(new stdClass(), true))->isSetupRequired());
    }

    public function testIncompleteSetupProgressDetector(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $file     = $this->dir . '/progress.json';
        $storage  = new FilesystemSetupProgressStorage($file);
        $detector = new IncompleteSetupProgressDetector($storage, $markers, enabled: true);

        self::assertFalse($detector->isSetupRequired());
        self::assertSame('ok', $detector->getReason());

        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_WAITING, currentStepId: 'admin_user_6', percent: 50.0));
        self::assertTrue($detector->isSetupRequired());
        self::assertStringContainsString('incomplete setup progress', $detector->getReason());
        self::assertStringContainsString('admin_user_6', $detector->getReason());

        $markers->markDone();
        self::assertFalse($detector->isSetupRequired());

        $disabled = new IncompleteSetupProgressDetector($storage, $markers, enabled: false);
        self::assertFalse($disabled->isSetupRequired());
    }

    public function testIncompleteSetupProgressDetectorSwallowsStorageErrors(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/req2', $this->dir . '/done2');
        $broken  = new class implements SetupProgressStorageInterface {
            public function load(): SetupProgress
            {
                throw new RuntimeException('boom');
            }

            public function save(SetupProgress $progress): void
            {
            }
        };
        $detector = new IncompleteSetupProgressDetector($broken, $markers, enabled: true);
        self::assertFalse($detector->isSetupRequired());
        self::assertSame('ok', $detector->getReason());
    }

    public function testIncompleteSetupProgressDetectorReasonWhenSecondLoadFails(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/req3', $this->dir . '/done3');
        $flaky   = new class implements SetupProgressStorageInterface {
            private int $calls = 0;

            public function load(): SetupProgress
            {
                ++$this->calls;
                if ($this->calls === 1) {
                    return new SetupProgress(
                        phase: SetupProgress::PHASE_FAILED,
                        currentStepId: 'x',
                    );
                }

                throw new RuntimeException('second load fails');
            }

            public function save(SetupProgress $progress): void
            {
            }
        };
        $detector = new IncompleteSetupProgressDetector($flaky, $markers, enabled: true);
        // getReason → isSetupRequired (load #1 incomplete) → load #2 throws
        self::assertSame('incomplete setup progress', $detector->getReason());
    }
}
