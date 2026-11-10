<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Log;

use Nowo\SiteBackupBundle\Log\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function mb_strlen;
use function str_repeat;

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

    /**
     * Realistic driver / client failures a restore or a setup step can surface (1.34+): the secret must never survive.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideDriverErrors(): iterable
    {
        yield 'pdo mysql dsn' => [
            'SQLSTATE[HY000] [2002] Connection refused (DSN: mysql:host=db;port=3306;dbname=shop;user=app;password=REDACTED-TEST;charset=utf8mb4)',
            'SQLSTATE[HY000] [2002] Connection refused (DSN: mysql:host=db;port=3306;dbname=shop;user=app;password=***;charset=utf8mb4)',
        ];
        yield 'pdo pgsql dsn' => [
            'could not connect with pgsql:host=pg;port=5432;dbname=app;user=app;password=REDACTED-TEST',
            'could not connect with pgsql:host=pg;port=5432;dbname=app;user=app;password=***',
        ];
        yield 'doctrine url' => [
            'An exception occurred in the driver: could not connect to postgresql://app:REDACTED-TEST@pg:5432/app?serverVersion=16',
            'An exception occurred in the driver: could not connect to postgresql://app:***@pg:5432/app?serverVersion=16',
        ];
        yield 'mysql access denied keeps the hint' => [
            "SQLSTATE[HY000] [1045] Access denied for user 'app'@'172.18.0.3' (using password: YES)",
            "SQLSTATE[HY000] [1045] Access denied for user 'app'@'172.18.0.3' (using password: YES)",
        ];
        yield 'mysql client command line' => [
            'The command "mysql --host=db --user=root -pREDACTED-TEST shop < dump.sql" failed. ERROR 2005 (HY000): Unknown MySQL server host',
            'The command "mysql --host=db --user=root -p*** shop < dump.sql" failed. ERROR 2005 (HY000): Unknown MySQL server host',
        ];
        yield 'mysql_pwd env' => [
            'Process MYSQL_PWD=REDACTED-TEST mysql shop exited with code 1',
            'Process MYSQL_PWD=*** mysql shop exited with code 1',
        ];
        yield 'psql conninfo' => [
            'psql: error: connection to server at "pg" (172.18.0.2), port 5432 failed: FATAL:  password authentication failed for user "app" (host=pg port=5432 dbname=app user=app password=REDACTED-TEST)',
            'psql: error: connection to server at "pg" (172.18.0.2), port 5432 failed: FATAL:  password authentication failed for user "app" (host=pg port=5432 dbname=app user=app password=***',
        ];
        yield 'pgpassword env' => [
            'The command "PGPASSWORD=REDACTED-TEST psql -h pg -U app app" failed.',
            'The command "PGPASSWORD=*** psql -h pg -U app app" failed.',
        ];
        yield 'import of a create user statement' => [
            "SQL import: SQLSTATE[HY000]: General error: 1396 Operation CREATE USER failed for 'report'@'%' near CREATE USER 'report'@'%' IDENTIFIED BY 'REDACTED-TEST'",
            "SQL import: SQLSTATE[HY000]: General error: 1396 Operation CREATE USER failed for 'report'@'%' near CREATE USER 'report'@'%' IDENTIFIED BY '***'",
        ];
        yield 'import of an alter user statement' => [
            "SQL import: SQLSTATE[42601]: Syntax error: ALTER USER report WITH PASSWORD 'REDACTED-TEST' VALID UNTIL",
            "SQL import: SQLSTATE[42601]: Syntax error: ALTER USER report WITH PASSWORD '***' VALID UNTIL",
        ];
        yield 'import of a native password hash' => [
            "near IDENTIFIED WITH mysql_native_password AS '*REDACTED-TEST' at line 1",
            "near IDENTIFIED WITH mysql_native_password AS '***' at line 1",
        ];
    }

    #[DataProvider('provideDriverErrors')]
    public function testRedactsRealisticDriverErrors(string $message, string $expected): void
    {
        $redacted = SecretRedactor::redact($message);

        self::assertSame($expected, $redacted);
        self::assertStringNotContainsString('REDACTED-TEST', $redacted);
    }

    public function testForStorageRedactsAndCaps(): void
    {
        $long = 'password=REDACTED-TEST ' . str_repeat('x', 1000);

        $stored = SecretRedactor::forStorage($long);
        self::assertSame(SecretRedactor::STORAGE_MAX_LENGTH, mb_strlen($stored));
        self::assertStringStartsWith('password=*** ', $stored);
        self::assertSame('password=***', SecretRedactor::forStorage('password=REDACTED-TEST', 50));
        self::assertSame('pass', SecretRedactor::forStorage('password=REDACTED-TEST', 4));
    }

    public function testFromThrowableUsesOnlyTheRedactedMessage(): void
    {
        $e = new RuntimeException('connect mysql://app:REDACTED-TEST@db/shop failed', 0, new RuntimeException('previous password=REDACTED-TEST'));

        self::assertSame('connect mysql://app:***@db/shop failed', SecretRedactor::fromThrowable($e));
    }

    #[DataProvider('provideMessages')]
    public function testRedact(string $message, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::redact($message));
    }
}
