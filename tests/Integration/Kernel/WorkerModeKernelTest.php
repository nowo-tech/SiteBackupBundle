<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\Model\ArchivePassword;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WeakMap;

use function count;
use function gc_collect_cycles;
use function is_array;
use function json_decode;
use function memory_get_usage;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-RUNTIME-002 worker test: one kernel kept alive, requests driven through `http_kernel` directly, so the
 * `services_resetter` never runs between them (the case where reset-only state would leak). Two panel clients
 * alternate on the same shared services: an operator logged in through the host gate and an anonymous restore-page
 * poller. Each response holds only what that client may see, and memory stays flat.
 */
final class WorkerModeKernelTest extends TestCase
{
    /** Requests per client (2 × 500 = 1,000 requests in one kernel). */
    private const ROUNDS = 500;

    /** Allowed memory growth between the warm-up and the last round. */
    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_worker_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testPanelClientsDoNotShareStateWithoutTheServicesResetter(): void
    {
        $kernel = new DddHostKernel($this->baseDir, false, 'REDACTED-TEST-x');
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $httpKernel = $container->get('http_kernel');
            self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
            $router = $container->get('router');
            self::assertInstanceOf(UrlGeneratorInterface::class, $router);
            $path = $router->generate('nowo_site_backup_progress');

            $baseline = 0;
            for ($i = 0; $i < self::ROUNDS; ++$i) {
                $operator = $this->poll($httpKernel, $path, '10.9.0.1', true);
                $guest    = $this->poll($httpKernel, $path, '10.9.0.2', false);

                self::assertArrayHasKey('log', $operator, "Round {$i}: the operator sees the restore log.");
                self::assertSame(['active', 'phase', 'percent', 'message'], array_keys($guest), "Round {$i}: the guest never inherits the operator's view.");

                if ($i === 10) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
            }

            gc_collect_cycles();
            self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over 1,000 requests.');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testArchivePasswordWeakMapKeepsNoEntryAfterItsObjects(): void
    {
        $values = new ReflectionProperty(ArchivePassword::class, 'values');
        $alice  = 'REDACTED-TEST-alice-0123456789';
        $bob    = 'REDACTED-TEST-bob-0123456789ab';

        for ($i = 0; $i < 1000; ++$i) {
            $first  = ArchivePassword::fromString($i % 2 === 0 ? $alice : $bob);
            $second = ArchivePassword::fromString($i % 2 === 0 ? $bob : $alice);
            self::assertSame($i % 2 === 0 ? $alice : $bob, $first->reveal());
            self::assertSame($i % 2 === 0 ? $bob : $alice, $second->reveal());
            if ($i % 3 === 0) {
                $first->wipe();
            }
            unset($first, $second);
        }

        gc_collect_cycles();
        $map = $values->getValue();
        self::assertInstanceOf(WeakMap::class, $map);
        self::assertCount(0, $map, 'Every entry dies with its password object.');
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(HttpKernelInterface $httpKernel, string $path, string $ip, bool $operator): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        if ($operator) {
            $session->set('host_ops_ok', true);
        }
        $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip]);
        $request->setSession($session);

        $response = $httpKernel->handle($request);
        if ($httpKernel instanceof TerminableInterface) {
            $httpKernel->terminate($request, $response);
        }
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue(is_array($data));
        self::assertGreaterThan(0, count($data));

        /* @var array<string, mixed> $data */
        return $data;
    }
}
