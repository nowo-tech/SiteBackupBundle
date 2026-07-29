<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
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

    public function testDoctrineConnectDetector(): void
    {
        $ok = new class {
            public function executeQuery(string $sql): void
            {
            }
        };
        self::assertFalse((new DoctrineConnectDetector($ok, true))->isSetupRequired());

        $fail = new class {
            public function executeQuery(string $sql): void
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
}
