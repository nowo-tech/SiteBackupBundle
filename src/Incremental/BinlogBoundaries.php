<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use RuntimeException;

use function array_search;
use function count;
use function fclose;
use function fgets;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function in_array;
use function is_array;
use function is_int;
use function preg_match;
use function sprintf;
use function strlen;
use function unpack;

/**
 * Where the events of decoded binary logs really start and end (1.36+, PP-D1-H1).
 *
 * `mysqlbinlog` prints statement text verbatim, so a string literal of another tenant can hold lines that look like
 * `# at N`, an event header or `# End of log file`. Event boundaries are therefore taken from the binary log structure,
 * never from the text: a `# at N` line is a boundary only when N is the next event start of the log being decoded, an
 * event header only right after such a boundary and with the indexed end position, the end-of-log trailer only once
 * every event was seen. Anything else is a problem (the caller fails closed).
 *
 * Two sources:
 * - {@see self::fromRawFiles()}: the v4 headers of the captured logs (19-byte common header: timestamp, type,
 *   server_id, event_size, log_pos, flags; first event at offset 4);
 * - {@see self::recorded()}: the boundary sequence recorded at capture time for the filtered events file
 *   (`events.idx`, covered by the archive checksums), one list per input file.
 *
 * One instance per filter run (stateful cursor, never shared).
 */
final class BinlogBoundaries
{
    private const MAGIC = "\xfebin";

    private const HEADER_BYTES = 19;

    /** @var list<list<array{0: int, 1: int}>> */
    private array $files = [];

    /** @var list<list<array{0: int, 1: ?int}>> */
    private array $sequences = [];

    private bool $recordedMode = false;

    private int $file = 0;

    private int $pointer = 0;

    private string $phase = 'fde';

    private ?int $expectedEnd = null;

    private bool $awaitingHeader = false;

    private bool $headerRequired = false;

    private bool $ended = false;

    private function __construct(private readonly ?int $startPosition = null)
    {
    }

    /**
     * @param list<string> $paths raw binary logs, in decoding order
     * @param int|null $startPosition `--start-position` of the first file
     *
     * @throws RuntimeException when a file is not a well-formed v4 binary log
     */
    public static function fromRawFiles(array $paths, ?int $startPosition): self
    {
        $boundaries = new self($startPosition);
        foreach ($paths as $path) {
            $boundaries->files[] = self::index($path);
        }

        return $boundaries;
    }

    /**
     * @param list<list<array{0: int, 1: ?int}>> $sequences per input: `# at` positions and the header end (or null)
     */
    public static function recorded(array $sequences): self
    {
        $boundaries               = new self();
        $boundaries->sequences    = $sequences;
        $boundaries->recordedMode = true;

        return $boundaries;
    }

    /**
     * The boundary sequence of a filtered events file (written by {@see BinlogSchemaFilter} from validated input): every
     * `# at N` line and the `end_log_pos` of the header after it (null for header-less lines).
     *
     * @return list<array{0: int, 1: ?int}>
     */
    public static function recordFrom(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The filtered events are not readable.');
        }
        $sequence = [];
        try {
            while (($line = fgets($handle, 1 << 20)) !== false) {
                if (preg_match('/^# at (\d+)\s*$/', $line, $m) === 1) {
                    $sequence[] = [(int) $m[1], null];
                } elseif ($sequence !== [] && preg_match('/^#\d{6}\s+\d{1,2}:\d{2}:\d{2}\s+server id\s+\d+\s+end_log_pos\s+(\d+)/', $line, $m) === 1) {
                    $last            = count($sequence) - 1;
                    $sequence[$last] = [$sequence[$last][0], (int) $m[1]];
                }
            }
        } finally {
            fclose($handle);
        }

        return $sequence;
    }

    /**
     * Event [start, end] pairs of a v4 binary log.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function index(string $path): array
    {
        $size   = @filesize($path);
        $handle = @fopen($path, 'r');
        if ($handle === false || $size === false) {
            throw new RuntimeException('A captured binary log is not readable.');
        }

        try {
            if (fread($handle, 4) !== self::MAGIC) {
                throw new RuntimeException('A captured binary log has no v4 magic number.');
            }
            $events = [];
            $offset = 4;
            while ($offset < $size) {
                fseek($handle, $offset);
                $header = (string) fread($handle, self::HEADER_BYTES);
                if (strlen($header) !== self::HEADER_BYTES) {
                    throw new RuntimeException(sprintf('A captured binary log is truncated at %d.', $offset));
                }
                $fields = unpack('Vtimestamp/Ctype/Vserver/Vsize/Vend/vflags', $header);
                $length = is_array($fields) && is_int($fields['size'] ?? null) ? $fields['size'] : 0;
                $end    = $offset + $length;
                if ($length < self::HEADER_BYTES || $end > $size || (($fields['end'] ?? 0) !== 0 && $fields['end'] !== $end)) {
                    throw new RuntimeException(sprintf('A captured binary log has a malformed event at %d.', $offset));
                }
                $events[] = [$offset, $end];
                $offset   = $end;
            }

            return $events;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The next input file of a recorded run begins (no-op for raw files: one decoded stream).
     */
    public function beginInput(int $input): void
    {
        if ($this->recordedMode) {
            $this->file           = $input; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->pointer        = 0; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->awaitingHeader = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->headerRequired = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->ended          = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        }
    }

    /**
     * A `# at N` line: null when it is the next real boundary, else why not.
     */
    public function at(int $position): ?string
    {
        $this->ended = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        if ($this->headerRequired) {
            return sprintf('the event before %d has no header', $position);
        }

        return $this->recordedMode ? $this->recordedAt($position) : $this->rawAt($position);
    }

    /**
     * An event header line with its `end_log_pos`.
     */
    public function header(int $end, bool $formatDescription): ?string
    {
        if (!$this->awaitingHeader) {
            return sprintf('an event header (end %d) that is not at an event start', $end);
        }
        if ($end !== $this->expectedEnd) {
            return sprintf('an event header ends at %d instead of %s', $end, (string) $this->expectedEnd);
        }
        if ($this->phase === 'fde-header' && !$formatDescription) {
            return 'the log does not start with its format description';
        }
        $this->awaitingHeader = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->headerRequired = false; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        if ($this->phase === 'fde-header') {
            $this->phase   = $this->file === 0 && $this->startPosition !== null ? 'seek' : 'strict'; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->pointer = 1; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        }

        return null;
    }

    /**
     * The `# End of log file` trailer: allowed only once the stream is consumed.
     */
    public function end(): ?string
    {
        if ($this->headerRequired) {
            return 'the last event has no header';
        }
        $problem = $this->recordedMode
            ? ($this->pointer === count($this->sequences[$this->file] ?? []) ? null : 'the events end before their recorded index')
            : ($this->phase === 'strict' && $this->pointer === count($this->files[$this->file] ?? []) ? null : 'a log ends before its last event');
        $this->ended = $problem === null; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.

        return $problem;
    }

    /**
     * The decoded input is over: null when its last line was the end-of-log trailer of the last log.
     */
    public function complete(): ?string
    {
        if (!$this->ended) {
            return 'the decoded events stop before the end of the log';
        }

        return $this->recordedMode || $this->file === count($this->files) - 1 ? null : 'a captured log was not decoded';
    }

    private function recordedAt(int $position): ?string
    {
        $entry = $this->sequences[$this->file][$this->pointer] ?? null;
        if ($entry === null || $entry[0] !== $position) {
            return sprintf('"# at %d" is not the next recorded event', $position);
        }
        ++$this->pointer; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->expectedEnd    = $entry[1]; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->awaitingHeader = $entry[1] !== null; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->headerRequired = $entry[1] !== null; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.

        return null;
    }

    private function rawAt(int $position): ?string
    {
        $events = $this->files[$this->file] ?? [];
        if ($this->phase === 'strict' && $this->pointer >= count($events) && isset($this->files[$this->file + 1])) {
            ++$this->file; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $this->phase = 'fde'; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
            $events      = $this->files[$this->file];
        }
        $starts = [];
        foreach ($events as [$start]) {
            $starts[] = $start;
        }

        switch ($this->phase) {
            case 'fde':
                // The format description is printed first, labelled with any event start of its log.
                if ($events === [] || !in_array($position, $starts, true)) {
                    return sprintf('"# at %d" is not an event start of the log', $position);
                }
                $this->phase          = 'fde-header'; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
                $this->expectedEnd    = $events[0][1]; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
                $this->awaitingHeader = true; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.

                return null;
            case 'fde-header':
                return 'the format description has no header';
            case 'seek':
                $index = array_search($position, $starts, true);
                if ($position !== $this->startPosition || !is_int($index)) {
                    return sprintf('"# at %d" is not the start position', $position);
                }
                $this->pointer = $index; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
                $this->phase   = 'strict'; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
                break;
        }

        $event = $events[$this->pointer] ?? null;
        if ($event === null || $event[0] !== $position) {
            return sprintf('"# at %d" is not the next event start', $position);
        }
        ++$this->pointer; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->expectedEnd    = $event[1]; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.
        $this->awaitingHeader = true; // @igor-ignore - Per-run cursor: one instance per filter run, never a service.

        return null;
    }
}
