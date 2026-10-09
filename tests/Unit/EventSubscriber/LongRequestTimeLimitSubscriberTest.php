<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\EventSubscriber;

use Nowo\SiteBackupBundle\EventSubscriber\LongRequestTimeLimitSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function ini_get;

final class LongRequestTimeLimitSubscriberTest extends TestCase
{
    /** @var list<int> */
    private array $calls = [];

    private function subscriber(int $seconds = 600): LongRequestTimeLimitSubscriber
    {
        return new LongRequestTimeLimitSubscriber(
            ['/_site_backup', '/_setup/', '/es/_setup', ''],
            $seconds,
            function (int $s): bool {
                $this->calls[] = $s;

                return true;
            },
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'panel root' => ['/_site_backup', true];
        yield 'panel restore' => ['/_site_backup/restore/abc', true];
        yield 'setup advance' => ['/_setup/api/advance', true];
        yield 'localized setup' => ['/es/_setup/wizard', true];
        yield 'other locale' => ['/fr/_setup', false];
        yield 'lookalike' => ['/_site_backupx', false];
        yield 'home' => ['/', false];
    }

    #[DataProvider('paths')]
    public function testAppliesTo(string $path, bool $expected): void
    {
        self::assertSame($expected, $this->subscriber()->appliesTo($path));
    }

    public function testBumpsOnlyMatchingMainRequests(): void
    {
        $sub    = $this->subscriber(900);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $sub->onKernelRequest(new RequestEvent($kernel, Request::create('/_setup/api/advance'), HttpKernelInterface::MAIN_REQUEST));
        $sub->onKernelRequest(new RequestEvent($kernel, Request::create('/_setup/api/advance'), HttpKernelInterface::SUB_REQUEST));
        $sub->onKernelRequest(new RequestEvent($kernel, Request::create('/blog'), HttpKernelInterface::MAIN_REQUEST));

        self::assertSame([900], $this->calls);
    }

    public function testNegativeSecondsClampToUnlimitedAndDefaultSetter(): void
    {
        $this->subscriber(-5)->onKernelRequest(new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/_site_backup'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
        self::assertSame([0], $this->calls);

        $before = (int) ini_get('max_execution_time');
        $real   = new LongRequestTimeLimitSubscriber(['/_site_backup'], $before);
        $real->onKernelRequest(new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/_site_backup'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
        self::assertSame($before, (int) ini_get('max_execution_time'));
    }
}
