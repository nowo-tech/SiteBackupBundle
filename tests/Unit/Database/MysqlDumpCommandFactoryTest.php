<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MysqlDumpCommandFactoryTest extends TestCase
{
    public function testBuildsArgvWithoutPassword(): void
    {
        $spec = MysqlDumpCommandFactory::fromDatabaseUrl('mysql://app%40x:s%3Ecret@db.internal:3307/my%5Fdb?serverVersion=8.0&charset=utf8mb4');

        self::assertSame([
            'mysqldump',
            '--single-transaction',
            '--no-tablespaces',
            '--host=db.internal',
            '--port=3307',
            '--user=app@x',
            'my_db',
        ], $spec['command']);
        self::assertSame(['MYSQL_PWD' => 's>cret'], $spec['env']);
        self::assertNotContains('--routines', $spec['command']);
        foreach ($spec['command'] as $arg) {
            self::assertStringNotContainsString('s>cret', $arg);
        }
    }

    public function testOptionalFlagsSocketAndBinary(): void
    {
        $spec = MysqlDumpCommandFactory::fromDatabaseUrl(
            'mariadb://root@localhost/app?unix_socket=/run/mysqld/mysqld.sock',
            true,
            'mariadb-dump',
            ['--column-statistics=0', ''],
        );

        self::assertSame([
            'mariadb-dump',
            '--single-transaction',
            '--no-tablespaces',
            '--skip-ssl-verify-server-cert',
            '--socket=/run/mysqld/mysqld.sock',
            '--user=root',
            '--column-statistics=0',
            'app',
        ], $spec['command']);
        self::assertSame(['MYSQL_PWD' => ''], $spec['env']);
    }

    public function testDefaultPortAndEmptySocketFallsBackToHost(): void
    {
        $spec = MysqlDumpCommandFactory::fromDatabaseUrl('mysql://u:p@h/d?unix_socket=');
        self::assertContains('--host=h', $spec['command']);
        self::assertContains('--port=3306', $spec['command']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => ['', 'missing or invalid'];
        yield 'garbage' => ['::::', 'missing or invalid'];
        yield 'postgres' => ['postgresql://u:p@h/d', 'MySQL / MariaDB'];
        yield 'no database' => ['mysql://u:p@h/', 'no database'];
        yield 'no host' => ['mysql:/d', 'no host'];
    }

    #[DataProvider('invalid')]
    public function testInvalidUrls(string $url, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        MysqlDumpCommandFactory::fromDatabaseUrl($url);
    }
}
