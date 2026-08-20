<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

use Doctrine\DBAL\Connection;
use PDO;
use Throwable;

use function is_callable;
use function is_object;
use function is_string;
use function method_exists;
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
        private mixed $connection = null,
        private ?string $host = null,
        private ?int $port = null,
        private ?string $user = null,
        private ?string $password = null,
        private ?string $database = null,
        private bool $requireApplicationTables = true,
        private ?PDO $pdo = null,
    ) {
    }

    public function schemaExists(): bool
    {
        if (is_object($this->connection) && method_exists($this->connection, 'executeQuery')) {
            return $this->probeConnection($this->connection);
        }

        return $this->probePdo();
    }

    /**
     * Duck-typed DBAL connection (real {@see Connection} or test fake).
     */
    private function probeConnection(object $connection): bool
    {
        $executeQuery = [$connection, 'executeQuery'];
        if (!is_callable($executeQuery)) {
            return false;
        }

        try {
            $executeQuery('SELECT 1');
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
        $pdo = $this->pdo;
        if (!$pdo instanceof PDO) {
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
            } catch (Throwable) {
                return false;
            }
        }

        try {
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

    private function hasNonSetupTablesViaConnection(object $connection): bool
    {
        if (!is_string($this->database) || $this->database === '') {
            // Without a configured database name, treat SELECT 1 as enough.
            return true;
        }

        try {
            $executeQuery = [$connection, 'executeQuery'];
            if (!is_callable($executeQuery)) {
                return false;
            }

            $result = $executeQuery(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME NOT LIKE ?
                 LIMIT 1',
                [$this->database, self::SETUP_TABLE_PREFIX],
            );

            $fetchOne = is_object($result) ? [$result, 'fetchOne'] : null;
            if (!is_callable($fetchOne)) {
                return false;
            }

            return $fetchOne() !== false;
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
        $current = $e;
        while ($current instanceof Throwable) {
            $message = $current->getMessage();
            if (str_contains($message, 'Unknown database') || str_contains($message, '1049')) {
                return true;
            }

            $previous = $current->getPrevious();
            if (!$previous instanceof Throwable) {
                break;
            }

            $current = $previous;
        }

        return false;
    }
}
