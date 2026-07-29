<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function count;

use const PHP_BINARY;

final class SetupStepFactoryTest extends TestCase
{
    private string $dir;
    private SetupStepFactory $factory;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-factory-' . uniqid('', true);
        (new Filesystem())->mkdir($this->dir . '/var');
        $markers       = new SetupMarkerManager($this->dir . '/req', $this->dir . '/done');
        $runner        = new ConsoleProcessRunner($this->dir, PHP_BINARY, 30);
        $this->factory = new SetupStepFactory($runner, $markers, new NullAdminUserProvisioner());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testCreatesAllKnownStepTypes(): void
    {
        $configs = [
            ['type' => 'requirements'],
            ['type' => 'database_url'],
            ['type' => 'database_create'],
            ['type' => 'cache_clear'],
            ['type' => 'schema_update'],
            ['type' => 'migrations'],
            ['type' => 'sql_file', 'paths' => []],
            ['type' => 'console', 'command' => 'cache:clear'],
            ['type' => 'admin_user'],
            ['type' => 'sample_data', 'commands' => ['cache:clear']],
            ['type' => 'marker'],
        ];

        $steps = $this->factory->createAll($configs);
        self::assertCount(count($configs), $steps);
        self::assertSame('requirements_0', $steps[0]->getId());
        self::assertSame('Check requirements', $steps[0]->getLabel());
    }

    public function testMissingTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->create([], 0);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->create(['type' => 'unknown'], 0);
    }

    public function testConsoleWithoutCommandThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->create(['type' => 'console'], 0);
    }

    public function testCustomStepOverride(): void
    {
        $custom = new class implements SetupStepInterface {
            public function getId(): string
            {
                return 'custom';
            }

            public function getLabel(): string
            {
                return 'Custom';
            }

            public function getUiKind(): string
            {
                return 'auto';
            }

            public function isEnabled(SetupContext $ctx): bool
            {
                return true;
            }

            public function isComplete(SetupContext $ctx): bool
            {
                return false;
            }

            public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
            {
                return SetupStepResult::ok('ok');
            }
        };

        $markers = new SetupMarkerManager($this->dir . '/req2', $this->dir . '/done2');
        $factory = new SetupStepFactory(
            new ConsoleProcessRunner($this->dir, PHP_BINARY, 30),
            $markers,
            new NullAdminUserProvisioner(),
            customSteps: ['marker' => $custom],
        );

        self::assertSame('custom', $factory->create(['type' => 'marker'], 0)->getId());
    }
}
