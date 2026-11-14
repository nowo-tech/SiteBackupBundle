<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use RuntimeException;

use function array_slice;
use function count;
use function explode;
use function fclose;
use function fgets;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Keeps only the backed-up schema's events of decoded binary logs (1.36+, pure: files in, file out).
 *
 * Input: `mysqlbinlog --skip-gtids` output (no `--database`: that filter attributes DDL to the session's default
 * database and loses `ALTER TABLE app.t` issued from another one). The input is read event by event (`# at N`):
 *
 * - row events (`Table_map` … rows event with `STMT_END_F`, plus `Rows_query` comments) are grouped per statement and
 *   attributed by the `Table_map` databases;
 * - `Query` events are attributed by {@see BinlogStatementClassifier} (qualified target, else the default database
 *   tracked from the `use` statements);
 * - format description, rotate, GTID, `Xid` and other session-only events are kept.
 *
 * In {@see self::FILTER} mode (capture) other-schema and server-level events are dropped; in {@see self::CHECK} mode
 * (restore, defence in depth) any such event is a problem. In both modes an event that cannot be attributed with
 * certainty (unknown statement, several schemas in one statement, a body under another default database) is a problem:
 * the caller fails closed. Every kept `Query` event runs under `use <schema>`.
 *
 * With `$stopAt`, the output ends before the first event at or after it (an open transaction is rolled back), like
 * `mysqlbinlog --stop-datetime`.
 */
final class BinlogSchemaFilter
{
    public const FILTER = 'filter';
    public const CHECK  = 'check';

    private const MAX_PROBLEMS = 20;

    private const HEADER_END = '/^#\d{6}\s+\d{1,2}:\d{2}:\d{2}\s+server id\s+\d+\s+end_log_pos\s+(\d+)/';

    private const HEADER = '/^#\d{6}\s+\d{1,2}:\d{2}:\d{2}\s+server id\s+\d+\s+end_log_pos\s+\d+[^\t]*\t([A-Za-z_-]+)/';

    private const ROW_EVENTS = ['Write_rows', 'Update_rows', 'Delete_rows', 'Write_rows_v1', 'Update_rows_v1', 'Delete_rows_v1', 'Update_rows_partial', 'Partial_update_rows'];

    private const VERBATIM = ['Start', 'Format_desc', 'Rotate', 'Stop', 'Previous-GTIDs', 'Previous_gtids', 'GTID', 'Anonymous_GTID', 'Xid', 'Heartbeat', 'Transaction_payload', 'Incident'];

    /**
     * @param list<string> $inputs decoded files, in order
     */
    public static function run(array $inputs, string $output, string $schema, string $mode, ?DateTimeImmutable $stopAt = null, ?BinlogBoundaries $boundaries = null): BinlogFilterResult
    {
        $out = @fopen($output, 'x');
        if ($out === false) {
            throw new RuntimeException('Unable to create the filtered binary log file.');
        }

        $state = ['kept' => 0, 'dropped' => 0, 'problems' => [], 'first' => null, 'last' => null, 'cut' => false, 'broken' => false];
        try {
            foreach ($inputs as $i => $input) {
                if ($state['cut'] || $state['broken']) {
                    break;
                }
                $boundaries?->beginInput($i);
                self::file($input, $out, $schema, $mode, $stopAt, $state, $boundaries);
            }
        } finally {
            fclose($out);
        }

        return new BinlogFilterResult($state['kept'], $state['dropped'], array_slice($state['problems'], 0, self::MAX_PROBLEMS), count($state['problems']), $state['first'], $state['last'], $state['cut']);
    }

    /**
     * @param resource $out
     * @param array{kept: int, dropped: int, problems: list<string>, first: ?DateTimeImmutable, last: ?DateTimeImmutable, cut: bool, broken: bool} $state
     */
    private static function file(string $input, $out, string $schema, string $mode, ?DateTimeImmutable $stopAt, array &$state, ?BinlogBoundaries $boundaries): void
    {
        $in = @fopen($input, 'r');
        if ($in === false) {
            throw new RuntimeException('A decoded binary log is not readable.');
        }

        $defaultDb = null;
        $event     = [];
        $group     = null;
        $trailer   = false;
        $preamble  = true;
        try {
            while (($line = fgets($in, 1 << 20)) !== false) {
                // The trailer of a log lasts until the next real event (several logs in one decoded stream).
                if ($trailer && !str_starts_with($line, '# at ')) {
                    fwrite($out, $line);
                    continue;
                }
                $trailer = false;
                $isAt    = str_starts_with($line, '# at ');
                $isEnd   = str_starts_with($line, '# End of log file');
                // PP-D1-H1: boundaries come from the binary structure; a boundary-looking line anywhere else (inside a
                // string literal of another tenant) stops everything.
                $structure = $boundaries instanceof BinlogBoundaries ? self::structure($line, $isAt, $isEnd, $boundaries) : null;
                if ($structure !== null) {
                    self::problem($state, $structure . ' (event boundaries do not match the binary logs)');
                    $state['broken'] = true;

                    return;
                }
                if ($preamble && !$isAt && !$isEnd) {
                    fwrite($out, $line);
                    continue;
                }
                $preamble = false;
                if (($isAt || $isEnd) && self::hasHeader($event)) {
                    self::event($event, $out, $schema, $mode, $stopAt, $state, $defaultDb, $group);
                    $event = [];
                    if ($state['cut']) {
                        return;
                    }
                }
                if ($isEnd) {
                    if ($group !== null) {
                        self::problem($state, 'a row event group is not closed at the end of a log');
                    }
                    $trailer = true;
                    fwrite($out, implode('', $event) . $line);
                    $event = [];
                    continue;
                }
                $event[] = $line;
            }
            if (self::hasHeader($event)) {
                self::event($event, $out, $schema, $mode, $stopAt, $state, $defaultDb, $group);
            }
            // A stream that stops early (or never reaches the last log) is not the whole capture.
            $incomplete = $boundaries instanceof BinlogBoundaries && !$state['cut'] ? $boundaries->complete() : null;
            if ($incomplete !== null) {
                self::problem($state, $incomplete . ' (event boundaries do not match the binary logs)');
                $state['broken'] = true;
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Checks one line against the real event structure: null when it is fine.
     */
    private static function structure(string $line, bool $isAt, bool $isEnd, BinlogBoundaries $boundaries): ?string
    {
        if ($isAt) {
            return preg_match('/^# at (\d+)\s*$/', $line, $m) === 1 ? $boundaries->at((int) $m[1]) : 'a malformed "# at" line';
        }
        if ($isEnd) {
            return $boundaries->end();
        }
        if (preg_match(self::HEADER_END, $line, $m) === 1) {
            $type = preg_match(self::HEADER, $line, $t) === 1 ? $t[1] : '';

            return $boundaries->header((int) $m[1], $type === 'Start' || $type === 'Format_desc');
        }

        return null;
    }

    /**
     * @param list<string> $event
     */
    private static function hasHeader(array $event): bool
    {
        foreach ($event as $line) {
            if (preg_match(self::HEADER, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $event lines: `# at` lines, the header, the content
     * @param resource $out
     * @param array{kept: int, dropped: int, problems: list<string>, first: ?DateTimeImmutable, last: ?DateTimeImmutable, cut: bool, broken: bool} $state
     * @param array{lines: list<string>, dbs: array<string, true>, at: string}|null $group
     */
    private static function event(array $event, $out, string $schema, string $mode, ?DateTimeImmutable $stopAt, array &$state, ?string &$defaultDb, ?array &$group): void
    {
        $at      = '?';
        $header  = null;
        $type    = '';
        $content = [];
        foreach ($event as $line) {
            if ($header === null && str_starts_with($line, '# at ')) {
                $at = trim(substr($line, 5));
                continue;
            }
            if ($header === null && preg_match(self::HEADER, $line, $m) === 1) {
                $header = $line;
                $type   = $m[1];
                continue;
            }
            if ($header !== null) {
                $content[] = $line;
            }
        }

        $time = BinlogEventTimes::parse(rtrim((string) $header, "\r\n"));
        if ($stopAt instanceof DateTimeImmutable && $time instanceof DateTimeImmutable && $time >= $stopAt) {
            // Like --stop-datetime: nothing at or after the cut; an open transaction is rolled back.
            fwrite($out, "ROLLBACK /* nowo-site-backup: --until */\n/*!*/;\n");
            $state['cut'] = true;
            $group        = null;

            return;
        }

        if ($type === 'Table_map' || $type === 'Rows_query' || in_array($type, self::ROW_EVENTS, true)) {
            $group ??= ['lines' => [], 'dbs' => [], 'at' => $at];
            foreach ($event as $line) {
                $group['lines'][] = $line;
            }
            if ($type === 'Table_map' && preg_match('/Table_map: `((?:[^`]|``)+)`\.`(?:[^`]|``)+`/', (string) $header, $m) === 1) {
                $group['dbs'][str_replace('``', '`', $m[1])] = true;
            }
            if (in_array($type, self::ROW_EVENTS, true) && str_contains((string) $header, 'STMT_END_F')) {
                self::rows($group, $out, $schema, $mode, $state, $time);
                $group = null;
            }

            return;
        }
        if ($group !== null) {
            self::problem($state, sprintf('at %s: a row event group is interrupted', $group['at']));
            $group = null;
        }

        if (in_array($type, self::VERBATIM, true)) {
            fwrite($out, implode('', $event));
            self::seen($state, $time, true);

            return;
        }

        [$verdict, $kept, $reason] = self::statements(implode('', $content), $schema, $defaultDb);
        if ($verdict === BinlogStatementClassifier::UNKNOWN) {
            self::problem($state, sprintf('at %s: %s (cannot be attributed to schema %s)', $at, $reason, $schema));

            return;
        }
        if ($verdict === BinlogStatementClassifier::OTHER || $verdict === BinlogStatementClassifier::SERVER) {
            if ($mode === self::CHECK) {
                self::problem($state, sprintf('at %s: %s (not schema %s)', $at, $reason, $schema));
            }
            ++$state['dropped'];

            return;
        }

        $lines = [];
        foreach ($event as $line) {
            if ($line === $header) {
                $lines[] = $line;
                break;
            }
            $lines[] = $line;
        }
        $body = 'use `' . str_replace('`', '``', $schema) . "`/*!*/;\n";
        foreach ($kept as $statement) {
            $body .= $statement . "\n/*!*/;\n";
        }
        fwrite($out, implode('', $lines) . $body);
        self::seen($state, $time, true);
    }

    /**
     * @param array{lines: list<string>, dbs: array<string, true>, at: string} $group
     * @param resource $out
     * @param array{kept: int, dropped: int, problems: list<string>, first: ?DateTimeImmutable, last: ?DateTimeImmutable, cut: bool, broken: bool} $state
     */
    private static function rows(array $group, $out, string $schema, string $mode, array &$state, ?DateTimeImmutable $time): void
    {
        $dbs = $group['dbs'];
        if ($dbs === []) {
            self::problem($state, sprintf('at %s: row events without a table map', $group['at']));

            return;
        }
        if (count($dbs) === 1 && isset($dbs[$schema])) {
            fwrite($out, implode('', $group['lines']));
            self::seen($state, $time, true);

            return;
        }
        if (isset($dbs[$schema])) {
            self::problem($state, sprintf('at %s: one statement changed rows in several databases', $group['at']));

            return;
        }
        if ($mode === self::CHECK) {
            self::problem($state, sprintf('at %s: rows of another database', $group['at']));
        }
        ++$state['dropped'];
    }

    /**
     * Splits a Query event's content into statements, tracks `use`, classifies.
     *
     * @return array{0: string, 1: list<string>, 2: string} verdict, statements to keep (no `use`), reason
     */
    private static function statements(string $content, string $schema, ?string &$defaultDb): array
    {
        $verdict = BinlogStatementClassifier::CONTROL;
        $reason  = 'session';
        $kept    = [];
        $main    = false;
        foreach (explode('/*!*/;', $content) as $piece) {
            $statement = trim($piece);
            if ($statement === '') {
                continue;
            }
            $normalised = BinlogStatementClassifier::normalise($statement);
            // PP-D1-H1: an event is [use] [session settings] then ONE statement. A second statement (a `/*!*/;` or a
            // `use` hidden in a literal or an identifier) is never trusted.
            if ($main && $normalised !== '') {
                return [BinlogStatementClassifier::UNKNOWN, [], 'several statements in one event'];
            }
            if (preg_match('/^use\s+`((?:[^`]|``)+)`\s*$/i', $normalised, $m) === 1) {
                $defaultDb = str_replace('``', '`', $m[1]);
                continue;
            }
            [$result, $why] = BinlogStatementClassifier::classify($statement, $defaultDb, $schema);
            $kept[]         = $statement;
            if (BinlogStatementClassifier::isSessionSetting($statement)) {
                continue;
            }
            $main = true;
            if ($result !== BinlogStatementClassifier::CONTROL) {
                $verdict = $result;
                $reason  = $why;
            }
        }

        return [$verdict, $kept, $reason];
    }

    /**
     * @param array{kept: int, dropped: int, problems: list<string>, first: ?DateTimeImmutable, last: ?DateTimeImmutable, cut: bool, broken: bool} $state
     */
    private static function seen(array &$state, ?DateTimeImmutable $time, bool $kept): void
    {
        if ($kept) {
            ++$state['kept'];
        }
        if ($time instanceof DateTimeImmutable) {
            $state['first'] ??= $time;
            $state['last'] = $time;
        }
    }

    /**
     * @param array{kept: int, dropped: int, problems: list<string>, first: ?DateTimeImmutable, last: ?DateTimeImmutable, cut: bool, broken: bool} $state
     */
    private static function problem(array &$state, string $problem): void
    {
        $state['problems'][] = $problem;
    }
}
