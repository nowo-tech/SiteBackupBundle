<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Log;

use Nowo\SiteBackupBundle\Log\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMessages(): iterable
    {
        yield 'url user info' => ['connect mysql://app:REDACTED-TEST@db:3306/shop failed', 'connect mysql://app:***@db:3306/shop failed'];
        yield 'url without password' => ['mysql://app@db/shop', 'mysql://app@db/shop'];
        yield 'password key' => ['password=REDACTED-TEST&x=1', 'password=***&x=1'];
        yield 'quoted pwd' => ['MYSQL_PWD="two words" next', 'MYSQL_PWD=*** next'];
        yield 'cli password' => ['mysqldump --password=REDACTED-TEST -pREDACTED-TEST db', 'mysqldump --password=*** -p*** db'];
        yield 'port untouched' => ['mysqldump --port=3306 -p db', 'mysqldump --port=3306 -p db'];
        yield 'plain' => ['tar create failed: no space left', 'tar create failed: no space left'];
    }

    #[DataProvider('provideMessages')]
    public function testRedact(string $message, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::redact($message));
    }
}
