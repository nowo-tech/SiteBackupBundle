<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Exclusion;

use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteBackupExclusionMatcherTest extends TestCase
{
    public function testPathPrefixAndExact(): void
    {
        $matcher = new SiteBackupExclusionMatcher(
            paths: ['/health'],
            pathPrefixes: ['/_site_backup'],
            routes: ['api_ping'],
            patterns: ['/ready*'],
            ips: ['127.0.0.1'],
        );

        self::assertTrue($matcher->matches(Request::create('/health')));
        self::assertTrue($matcher->matches(Request::create('/_site_backup/progress.json')));
        self::assertTrue($matcher->matches(Request::create('/readyz')));

        $routed = Request::create('/x');
        $routed->attributes->set('_route', 'api_ping');
        self::assertTrue($matcher->matches($routed));

        $fromLocal = Request::create('/nope', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertTrue($matcher->matches($fromLocal));

        self::assertFalse($matcher->matches(Request::create('/blog', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8'])));
    }
}
