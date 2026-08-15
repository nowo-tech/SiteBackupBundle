<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PDO;
use Throwable;

use function is_string;
use function str_contains;

/**
 * MySQL schema probe via optional DBAL connection or direct PDO credentials.
 */
final readonly class MysqlSchemaExistenceChecker implements SchemaExistenceCheckerInterface
{
    public function __construct(
        private ?Connection $connection = null,
        private ?string $host = null,
        private ?int $port = null,
        private ?string $user = null,
        private ?string $password = null,
        private ?string $database = null,
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

            return true;
        } catch (Throwable $e) {
            if ($this->isUnknownDatabase($e)) {
                return false;
            }

            return false;
        }
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

            return true;
        } catch (Throwable $e) {
            if ($this->isUnknownDatabase($e)) {
                return false;
            }

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
