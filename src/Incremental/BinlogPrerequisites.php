<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use SensitiveParameter;
use Symfony\Component\Process\Process;
use Throwable;

use function count;
use function explode;
use function implode;
use function in_array;
use function mb_substr;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;
use function strtoupper;
use function trim;

/**
 * Default {@see BinlogPrerequisitesInterface} (1.36+). Checks, in order:
 *
 * - `binaries`: Oracle `mysqldump`, `mysql` and `mysqlbinlog` 8.0.26+ (the MariaDB tools cannot read MySQL binlogs);
 * - `server`: a reachable MySQL 8.0.26+ server (not MariaDB) with a `server_uuid`;
 * - `log_bin`: ON;
 * - `binlog_format`: ROW (MIXED is a warning, STATEMENT fails: `--database` filtering is unreliable);
 * - `binlog_row_image`: FULL (others are a warning);
 * - `expiry`: `binlog_expire_logs_seconds` 0 or at least `$minExpirySeconds` (warning), never under an hour (fail):
 *   a log that expires before the next incremental turns the next run into a gap;
 * - `grants`: RELOAD (FLUSH BINARY LOGS, the dump's read lock), REPLICATION CLIENT (SHOW BINARY LOGS / STATUS) and
 *   REPLICATION SLAVE / REPLICA (mysqlbinlog --read-from-remote-server) on *.*.
 */
final class BinlogPrerequisites implements BinlogPrerequisitesInterface
{
    private const REQUIRED_GRANTS = ['RELOAD', 'REPLICATION CLIENT', 'REPLICATION SLAVE'];

    /**
     * @param array<string, string> $binaries label => binary (`mysqldump`, `mysql`, `mysqlbinlog`)
     */
    public function __construct(
        private readonly BinlogServerInterface $server,
        private readonly array $binaries = ['mysqldump' => 'mysqldump', 'mysql' => 'mysql', 'mysqlbinlog' => 'mysqlbinlog'],
        private readonly int $minExpirySeconds = 604800,
        private readonly int $probeTimeoutSeconds = 30,
        #[SensitiveParameter]
        private readonly ?string $database = null,
        private readonly string $captureMode = IncrementalArtifact::FILTERED,
    ) {
    }

    public function check(): array
    {
        $checks = [$this->binaries()];

        try {
            $version = $this->server->version();
            $uuid    = $this->server->serverUuid();
        } catch (Throwable $e) {
            $checks[] = Check::fail('server', 'The server cannot be queried: ' . mb_substr(SecretRedactor::redact($e->getMessage()), 0, 200));

            return $checks;
        }
        $oracle   = MysqlToolVersion::oracleServer($version);
        $checks[] = MysqlToolVersion::supports($oracle)
            ? Check::ok('server', sprintf('MySQL %s, server_uuid %s.', $oracle, $uuid))
            : Check::fail('server', sprintf('MySQL %s+ is required (got %s; MariaDB is not supported).', MysqlToolVersion::MINIMUM, mb_substr($version, 0, 60)));

        try {
            $vars = $this->server->variables(['log_bin', 'binlog_format', 'binlog_row_image', 'binlog_expire_logs_seconds', 'gtid_mode']);
        } catch (Throwable $e) {
            $checks[] = Check::fail('log_bin', 'Server variables cannot be read: ' . mb_substr(SecretRedactor::redact($e->getMessage()), 0, 200));

            return $checks;
        }

        $checks[] = in_array(strtoupper($vars['log_bin'] ?? ''), ['ON', '1'], true)
            ? Check::ok('log_bin', 'Binary logging is on.')
            : Check::fail('log_bin', 'Binary logging is off: start the server with log_bin.');

        $format   = strtoupper($vars['binlog_format'] ?? '');
        $checks[] = match ($format) {
            'ROW'   => Check::ok('binlog_format', 'ROW.'),
            default => Check::fail('binlog_format', sprintf('binlog_format is %s: ROW is required.', $format === '' ? 'unknown' : $format)),
        };

        $image    = strtoupper($vars['binlog_row_image'] ?? '');
        $checks[] = $image === 'FULL'
            ? Check::ok('binlog_row_image', 'FULL.')
            : Check::warning('binlog_row_image', sprintf('binlog_row_image is %s: FULL is recommended for replays.', $image === '' ? 'unknown' : $image));

        $checks[] = $this->expiry($vars['binlog_expire_logs_seconds'] ?? null);
        $checks[] = $this->grants();
        $checks[] = $this->schemas();
        $checks[] = Check::ok('gtid_mode', sprintf('gtid_mode is %s (replays skip GTIDs either way).', $vars['gtid_mode'] ?? 'OFF'));

        return $checks;
    }

    private function binaries(): Check
    {
        $problems = [];
        $versions = [];
        foreach ($this->binaries as $label => $binary) {
            $process = new Process([$binary, '--version']);
            try {
                ProcessDeadline::run($process, $this->probeTimeoutSeconds);
                $line = trim(explode("\n", trim($process->getOutput()))[0]);
            } catch (Throwable) {
                $line = '';
            }
            $version = $process->isSuccessful() ? MysqlToolVersion::oracleClient($line) : null;
            if (!MysqlToolVersion::supports($version)) {
                $problems[] = sprintf('%s ("%s") is missing, MariaDB or older than %s', $label, $binary, MysqlToolVersion::MINIMUM);
                continue;
            }
            $versions[] = $label . ' ' . $version;
        }

        return $problems === []
            ? Check::ok('binaries', implode(', ', $versions) . '.')
            : Check::fail('binaries', implode('; ', $problems) . ': install the Oracle MySQL client tools.');
    }

    private function expiry(?string $value): Check
    {
        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            return Check::warning('expiry', 'binlog_expire_logs_seconds is unknown.');
        }
        $seconds = (int) $value;
        if ($seconds === 0) {
            return Check::ok('expiry', 'Binary logs never expire (watch the disk).');
        }
        if ($seconds < 3600) {
            return Check::fail('expiry', sprintf('Binary logs expire after %d s: an incremental plan cannot keep up.', $seconds));
        }
        if ($seconds < $this->minExpirySeconds) {
            return Check::warning('expiry', sprintf('Binary logs expire after %d s (< %d s): a missed run becomes a gap sooner.', $seconds, $this->minExpirySeconds));
        }

        return Check::ok('expiry', sprintf('Binary logs expire after %d s.', $seconds));
    }

    /**
     * PP-M3: binary logs are server-wide. With other application schemas on the server, a raw capture archives their
     * data and account statements; a filtered capture keeps only this schema's events.
     */
    private function schemas(): Check
    {
        try {
            $schemas = $this->server->schemas();
        } catch (Throwable $e) {
            return Check::warning('schemas', 'SHOW DATABASES failed: ' . mb_substr(SecretRedactor::redact($e->getMessage()), 0, 200));
        }
        $own    = $this->database !== null ? self::databaseName($this->database) : null;
        $others = [];
        foreach ($schemas as $schema) {
            if (!in_array(strtolower($schema), ['mysql', 'information_schema', 'performance_schema', 'sys'], true) && $schema !== $own) {
                $others[] = $schema;
            }
        }
        if ($others === []) {
            return Check::ok('schemas', 'No other application schema on this server.');
        }
        $detail = sprintf('%d other schema(s) on this server (shared server).', count($others));

        return $this->captureMode === IncrementalArtifact::RAW
            ? Check::warning('schemas', $detail . ' binlog_capture: raw archives their events too: use filtered, or a dedicated server, and encrypt the archives.')
            : Check::ok('schemas', $detail . ' binlog_capture: filtered keeps only this schema\'s events.');
    }

    private static function databaseName(string $url): ?string
    {
        try {
            return MysqlDumpCommandFactory::databaseName($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function grants(): Check
    {
        try {
            $grants = $this->server->grants();
        } catch (Throwable $e) {
            return Check::fail('grants', 'SHOW GRANTS failed: ' . mb_substr(SecretRedactor::redact($e->getMessage()), 0, 200));
        }

        $held = [];
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT (.+?) ON \*\.\* TO /i', $grant, $m) !== 1) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', strtoupper($m[1])) ?: [] as $privilege) {
                $held[trim($privilege)] = true;
            }
        }
        if (isset($held['ALL PRIVILEGES']) || isset($held['ALL']) || isset($held['SUPER']) || isset($held['SYSTEM_VARIABLES_ADMIN'])) {
            // PP-M4: works, but far more than captures need (an injection in the app would own the server).
            return Check::warning('grants', 'The account holds ALL / SUPER / SYSTEM_VARIABLES_ADMIN on *.*: use a dedicated backup account (backup.database.binlog_url) with RELOAD, REPLICATION CLIENT, REPLICATION SLAVE only.');
        }

        $missing = [];
        foreach (self::REQUIRED_GRANTS as $required) {
            $alias = str_contains($required, 'SLAVE') ? 'REPLICATION REPLICA' : $required;
            if (!isset($held[$required]) && !isset($held[$alias])) {
                $missing[] = $required;
            }
        }

        return $missing === []
            ? Check::ok('grants', 'RELOAD, REPLICATION CLIENT, REPLICATION SLAVE.')
            : Check::fail('grants', sprintf('Missing on *.*: %s.', implode(', ', $missing)));
    }
}
