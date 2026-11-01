<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\DatabaseUrlSafety;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_slice;

/**
 * REQ-SEC-004 CG F2: DATABASE_URL components must never reach the mysqldump argv as options.
 */
final class DatabaseUrlSafetyTest extends TestCase
{
    /** A database name that mysqldump would read as an option. */
    public const POC_DSN = 'mysql://app:pw@10.0.0.5:6379/--result-file=/var/www/public/x.php?serverVersion=8.0&driverOptions%5B1001%5D=1';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'PoC database name' => [self::POC_DSN, 'database name must not start with "-"'];
        yield 'encoded dash database' => ['mysql://u:p@h/%2D%2Dhost=evil', 'database name must not start with "-"'];
        yield 'dash user' => ['mysql://-u:p@h/db', 'user must not start with "-"'];
        yield 'dash host' => ['mysql://u:p@-h/db', 'host must not start with "-"'];
        yield 'dash socket' => ['mysql://u:p@h/db?unix_socket=--defaults-file=/x', 'unix_socket must not start with "-"'];
        yield 'newline database' => ['mysql://u:p@h/db%0A--x', 'database name must not contain control characters'];
        yield 'tab user' => ['mysql://u%09x:p@h/db', 'user must not contain control characters'];
        yield 'DEL host' => ['mysql://u:p@h%7F/db', 'host must not contain control characters'];
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsOptionLikeOrControlComponents(string $url, string $expected): void
    {
        self::assertStringContainsString($expected, (string) DatabaseUrlSafety::violation($url));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);
        MysqlDumpCommandFactory::fromDatabaseUrl($url);
    }

    public function testAcceptsRegularUrlsAndIgnoresUnparsableOnes(): void
    {
        self::assertNull(DatabaseUrlSafety::violation('mysql://app:p-w@db-1.internal:3306/my-db?serverVersion=8.0'));
        self::assertNull(DatabaseUrlSafety::violation('mysql://app@localhost/app?unix_socket=/run/mysqld/mysqld.sock'));
        self::assertNull(DatabaseUrlSafety::violation('postgresql://app:pw@db/app'));
        self::assertNull(DatabaseUrlSafety::violation(''));
        self::assertNull(DatabaseUrlSafety::violation('http:///x'));
        self::assertNull(DatabaseUrlSafety::componentViolation('database name', 'a-b'));
    }

    public function testArgvEndsWithEndOfOptionsMarkerBeforeTheDatabase(): void
    {
        $spec    = MysqlDumpCommandFactory::fromDatabaseUrl('mysql://app:pw@db:3306/shop', false, 'mysqldump', ['--column-statistics=0']);
        $command = $spec['command'];

        self::assertSame(['--', 'shop'], array_slice($command, -2));
        self::assertCount(1, array_keys($command, '--', true));
    }
}
