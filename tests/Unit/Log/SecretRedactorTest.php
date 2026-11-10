<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Log;

use Nowo\SiteBackupBundle\Log\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function json_encode;
use function mb_check_encoding;
use function mb_strlen;
use function str_repeat;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

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

    /**
     * Audit KZ (SB-M1, SB-L2, SB-L3): inputs that leaked (part of) the secret before the fix.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideAuditLeaks(): iterable
    {
        // KZ-SB-M1 a: raw `@` inside a URL password.
        yield 'raw @ in url password' => ['Could not connect mysql://app:p@ss:w0rd@db:3306/app', ['ss:w0rd', 'p@ss']];
        yield 'ftp raw @ in password' => ['ftp://backup:Ftp@S3cret@host/path', ['S3cret', 'Ftp@']];
        // KZ-SB-M1 b: backslash-escaped quotes.
        yield 'single quoted escaped' => ["password='ab\\'cd ef'", ['cd ef', "ab\\'"]];
        yield 'double quoted escaped' => ['password="ab\\"cd ef"', ['cd ef', 'ab\\"']];
        yield 'pg conninfo escaped quote' => ["host=pg password='it\\'s secret' dbname=app", ['s secret']];
        // KZ-SB-M1 c: MySQL `\'` escape in IDENTIFIED BY.
        yield 'identified by escaped' => ["CREATE USER 'a'@'%' IDENTIFIED BY 'Id\\'S3 cret'", ['S3 cret', 'Id\\\'']];
        yield 'identified with by' => ["IDENTIFIED WITH caching_sha2_password BY 'Sha2S3cret'", ['Sha2S3cret']];
        // d: ODBC brace quoting.
        yield 'odbc braces' => ['Uid=app;Pwd={Odbc;S3cret};', ['S3cret', 'Odbc']];
        // KZ-SB-L2: passwords that start like the MySQL hint.
        yield 'password=YES)' => ['password=YES)RealSecret', ['RealSecret']];
        yield 'password: NO)' => ['auth failed; password: NO)RealSecret2', ['RealSecret2']];
        yield 'password: YES)secret' => ['password: YES)secret', [')secret']];
        // KZ-SB-L3 f: CLI forms.
        yield '--password space' => ['mysqldump --password RootS3cret app', ['RootS3cret']];
        yield 'quoted -pX argv' => ["The command \"'mysqldump' '-pProcS3cret' 'app'\" failed.", ['ProcS3cret']];
        yield 'quoted -p then value argv' => ["The command \"'mysqldump' '-p' 'ProcS3cret' 'app'\" failed.", ['ProcS3cret']];
        yield 'quoted --password= with space' => ["The command \"'mysql' '--password=Proc S3cret' 'app'\" failed.", ['Proc', 'S3cret']];
        yield '-p at start' => ['-pStartS3cret mysqldump', ['StartS3cret']];
        // KZ-SB-L3 g: key coverage.
        yield 'lower-case env' => ['database_password=lowS3cret aws_secret_access_key=lowAWS', ['lowS3cret', 'lowAWS']];
        yield 'yaml app_secret' => ['app_secret: YamlS3cret', ['YamlS3cret']];
        yield 'camelCase secretKey' => ['secretKey=CamelS3cret', ['CamelS3cret']];
        yield 'camelCase accessKeyId' => ['accessKeyId: CamelAK', ['CamelAK']];
        yield 'xml element' => ['<SecretAccessKey>XmlS3cret</SecretAccessKey>', ['XmlS3cret']];
        yield 'passphrase=' => ['encrypt failed passphrase=AgeP4ss', ['AgeP4ss']];
        yield 'quoted passphrase' => ['age: error: incorrect passphrase "AgeP4ss phrase"', ['AgeP4ss', 'phrase"']];
        yield 'age identity' => ['AGE-SECRET-KEY-1QYQSZQGPQYQSZQGPQYQSZQGPQ', ['QYQSZQGPQYQSZ']];
        yield 'multiline password' => ["DSN: mysql:host=db\npassword:\n  TopS3cret\n", ['TopS3cret']];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('provideAuditLeaks')]
    public function testAuditLeakingInputsAreFullyMasked(string $message, array $secrets): void
    {
        $redacted = SecretRedactor::redact($message);

        self::assertStringContainsString(SecretRedactor::MASK, $redacted);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $redacted);
        }
    }

    public function testExactMaskingOfTheAuditCases(): void
    {
        self::assertSame('Could not connect mysql://app:***@db:3306/app', SecretRedactor::redact('Could not connect mysql://app:p@ss:w0rd@db:3306/app'));
        self::assertSame("CREATE USER 'a'@'%' IDENTIFIED BY '***'", SecretRedactor::redact("CREATE USER 'a'@'%' IDENTIFIED BY 'Id\\'S3 cret'"));
        self::assertSame('host=pg password=*** dbname=app', SecretRedactor::redact("host=pg password='it\\'s secret' dbname=app"));
        self::assertSame("ALTER USER app PASSWORD '***' VALID", SecretRedactor::redact("ALTER USER app PASSWORD 'It''s s3cret' VALID"));
        self::assertSame("The command \"'mysqldump' '-p' '***' 'app'\" failed.", SecretRedactor::redact("The command \"'mysqldump' '-p' 'ProcS3cret' 'app'\" failed."));
        self::assertSame('<SecretAccessKey>***</SecretAccessKey>', SecretRedactor::redact('<SecretAccessKey>XmlS3cret</SecretAccessKey>'));
    }

    public function testOnlyTheExactMysqlHintStaysReadable(): void
    {
        $hint = "SQLSTATE[HY000] [1045] Access denied for user 'app'@'db' (using password: YES)";
        self::assertSame($hint, SecretRedactor::redact($hint));
        self::assertSame('(using password: NO)', SecretRedactor::redact('(using password: NO)'));
        self::assertSame('(using password: ***', SecretRedactor::redact('(using password: YESX)'));
    }

    public function testForStorageScrubsInvalidUtf8SoTheResultIsJsonEncodable(): void
    {
        // A latin1 byte from a driver error (e.g. `near 'caf\xE9'`): json_encode() threw on PHP 8.2 and left the
        // restore progress stuck "active" (audit KZ-SB-L1).
        $stored = SecretRedactor::forStorage("SQL import: near 'caf\xE9' password=Latin1S3cret");

        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
        self::assertStringNotContainsString('Latin1S3cret', $stored);
        self::assertSame('{"m":"SQL import: near \'caf?\' password=***"}', json_encode(['m' => $stored], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertSame(3, mb_strlen(SecretRedactor::forStorage("\xE9\xE9\xE9\xE9", 3)));
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
