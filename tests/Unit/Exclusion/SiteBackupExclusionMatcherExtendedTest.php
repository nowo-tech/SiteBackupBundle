<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Exclusion;

use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteBackupExclusionMatcherExtendedTest extends TestCase
{
    public function testRegexAndFnmatchPatterns(): void
    {
        $matcher = new SiteBackupExclusionMatcher(
            paths: [],
            pathPrefixes: [''],
            routes: [],
            patterns: ['#^/ready#', '~health~'],
            ips: [],
        );

        self::assertFalse($matcher->matches(Request::create('/blog')));
        self::assertTrue($matcher->matches(Request::create('/readyz')));
        self::assertTrue($matcher->matches(Request::create('/health-check')));
    }

    public function testEmptyPatternIsIgnored(): void
    {
        $matcher = new SiteBackupExclusionMatcher([], [], [], ['', '/blog'], []);
        self::assertTrue($matcher->matches(Request::create('/blog')));
    }

    public function testCidrIpMatch(): void
    {
        $matcher = new SiteBackupExclusionMatcher([], [], [], [], ['10.0.0.0/8']);
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.1.2.3']);
        self::assertTrue($matcher->matches($request));
    }
}
