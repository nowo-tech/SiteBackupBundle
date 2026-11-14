<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;

/**
 * The few server statements the binary log capture needs (1.36+, SPI). The default implementation runs them through
 * the `mysql` client with an option file ({@see MysqlClientBinlogServer}); tests and hosts with their own connection
 * may provide another one. Implementations hold no per-call state.
 *
 * Every method throws {@see BinlogUnavailableException} when the server cannot be reached or refuses the statement.
 */
interface BinlogServerInterface
{
    /**
     * `SELECT VERSION()`, e.g. `9.7.2` or `8.4.6-log`.
     */
    public function version(): string;

    /**
     * `@@GLOBAL.server_uuid` (lower case).
     */
    public function serverUuid(): string;

    /**
     * Global variables by name (`log_bin`, `binlog_format`, …); missing ones are absent from the result.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    public function variables(array $names): array;

    /**
     * `FLUSH BINARY LOGS`: closes the current binary log and opens the next one (needs RELOAD).
     */
    public function flushBinaryLogs(): void;

    /**
     * `SHOW BINARY LOGS`: every binary log still on the server, oldest first; the last one is the active one.
     *
     * @return list<array{name: string, size: int}>
     */
    public function binaryLogs(): array;

    /**
     * `SHOW BINARY LOG STATUS` (8.2+; `SHOW MASTER STATUS` before): the current file, position and executed GTID set.
     *
     * @return array{file: string, position: int, gtid_executed: string}
     */
    public function status(): array;

    /**
     * Every database on the server (`SHOW DATABASES`), system ones included.
     *
     * @return list<string>
     */
    public function schemas(): array;

    /**
     * `SHOW GRANTS FOR CURRENT_USER()`, one line per grant.
     *
     * @return list<string>
     */
    public function grants(): array;
}
