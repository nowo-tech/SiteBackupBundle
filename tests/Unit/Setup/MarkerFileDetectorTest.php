<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MarkerFileDetectorTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-setup-marker-' . uniqid('', true);
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testRequiredMarkerTriggers(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $markers->markRequired('post_restore');

        $detector = new MarkerFileDetector($markers, requireDoneMarker: false);
        self::assertTrue($detector->isSetupRequired());
        self::assertSame('post_restore', $markers->readRequiredProfile());
    }

    public function testRequireDoneMarker(): void
    {
        $markers  = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $detector = new MarkerFileDetector($markers, requireDoneMarker: true);
        self::assertTrue($detector->isSetupRequired());

        $markers->markDone();
        self::assertFalse($detector->isSetupRequired());
    }

    public function testEvaluatorAny(): void
    {
        $markers = new SetupMarkerManager($this->dir . '/_setup.required', $this->dir . '/_setup.done');
        $markers->markRequired();
        $evaluator = new SetupNeedEvaluator([
            new MarkerFileDetector($markers, false, true),
        ], true);
        self::assertTrue($evaluator->isSetupRequired());
        self::assertNotEmpty($evaluator->getReasons());
    }
}
