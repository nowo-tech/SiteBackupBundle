<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PDO;
use Throwable;

use function is_string;
use function sprintf;
use function str_contains;

/**
 * MySQL schema probe via optional DBAL connection or direct PDO credentials.
 *
 * {@see self::schemaExists()} is true only when the named database answers
 * {@code SELECT 1} and (when {@see $requireApplicationTables} is true) contains
 * at least one table that is not a SiteBackup runtime-DDL progress table.
 * An empty schema after {@code database_create} must stay "cold" so hosts do not
 * query missing Doctrine tables from Twig while migrations have not run yet.
 */
final readonly class MysqlSchemaExistenceChecker implements SchemaExistenceCheckerInterface
{
    private const SETUP_TABLE_PREFIX = 'nowo_site_backup%';

    public function __construct(
        private ?Connection $connection = null,
        private ?string $host = null,
        private ?int $port = null,
        private ?string $user = null,
        private ?string $password = null,
        private ?string $database = null,
        private bool $requireApplicationTables = true,
    ) {
    }

    public function schemaExists(): bool
    {
        if ($this->connection instanceof Connection) {
            return $this->probeConnection($this->connection);
        }

        return $this->probePdo();
    }

    private function probeConnection(Connection $connection): bool
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            if ($this->isUnknownDatabase($e)) {
                return false;
            }

            return false;
        }

        if (!$this->requireApplicationTables) {
            return true;
        }

        return $this->hasNonSetupTablesViaConnection($connection);
    }

    private function probePdo(): bool
    {
        if (!is_string($this->host) || $this->host === '' || !is_string($this->database) || $this->database === '') {
            return false;
        }

        $port = $this->port ?? 3306;
        $dsn  = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $port, $this->database);

        try {
            $pdo = new PDO(
                $dsn,
                is_string($this->user) ? $this->user : '',
                is_string($this->password) ? $this->password : '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            if ($this->isUnknownDatabase($e)) {
                return false;
            }

            return false;
        }

        if (!$this->requireApplicationTables) {
            return true;
        }

        return $this->hasNonSetupTablesViaPdo($pdo);
    }

    private function hasNonSetupTablesViaConnection(Connection $connection): bool
    {
        if (!is_string($this->database) || $this->database === '') {
            // Without a configured database name, treat SELECT 1 as enough.
            return true;
        }

        try {
            $result = $connection->executeQuery(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME NOT LIKE ?
                 LIMIT 1',
                [$this->database, self::SETUP_TABLE_PREFIX],
            );

            return $result->fetchOne() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasNonSetupTablesViaPdo(PDO $pdo): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME NOT LIKE ?
                 LIMIT 1',
            );
            $statement->execute([$this->database, self::SETUP_TABLE_PREFIX]);

            return $statement->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function isUnknownDatabase(Throwable $e): bool
    {
        if ($e instanceof Exception && $e->getPrevious() instanceof Throwable) {
            $e = $e->getPrevious();
        }

        $message = $e->getMessage();

        return str_contains($message, 'Unknown database')
            || str_contains($message, '1049');
    }
}
