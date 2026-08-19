<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\ColdStart;

use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use PHPUnit\Framework\TestCase;

final class MysqlSchemaExistenceCheckerTest extends TestCase
{
    public function testReturnsFalseWhenCredentialsMissing(): void
    {
        self::assertFalse((new MysqlSchemaExistenceChecker())->schemaExists());
    }

    public function testReturnsFalseWhenHostOrDatabaseEmpty(): void
    {
        self::assertFalse((new MysqlSchemaExistenceChecker(host: '', database: 'app'))->schemaExists());
        self::assertFalse((new MysqlSchemaExistenceChecker(host: 'db', database: ''))->schemaExists());
    }

    public function testProbePdoReturnsFalseWhenDatabaseUnreachable(): void
    {
        $checker = new MysqlSchemaExistenceChecker(
            host: '127.0.0.1',
            port: 1,
            user: 'root',
            password: '',
            database: 'nonexistent_db',
            requireApplicationTables: false,
        );

        self::assertFalse($checker->schemaExists());
    }
}
