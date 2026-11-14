<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Incremental\BinlogServerInterface;

use function count;
use function sprintf;
use function substr;

/**
 * An in-memory server: `SHOW BINARY LOGS` answers $logs; `FLUSH BINARY LOGS` opens the next log (or switches to
 * $afterFlush, to simulate a purge or a rotation between two statements).
 */
final class FakeBinlogServer implements BinlogServerInterface
{
    public int $flushes = 0;

    /**
     * @param list<array{name: string, size: int}> $logs
     * @param list<array{name: string, size: int}>|null $afterFlush
     */
    public function __construct(private array $logs, private readonly string $uuid, private readonly string $version, private readonly ?array $afterFlush, private readonly string $format = 'ROW')
    {
    }

    public function version(): string
    {
        return $this->version;
    }

    public function serverUuid(): string
    {
        return $this->uuid;
    }

    public function variables(array $names): array
    {
        return ['binlog_format' => $this->format];
    }

    public function schemas(): array
    {
        return ['app', 'mysql', 'sys'];
    }

    public function flushBinaryLogs(): void
    {
        ++$this->flushes;
        if ($this->afterFlush !== null) {
            $this->logs = $this->afterFlush;

            return;
        }
        $last         = $this->logs[count($this->logs) - 1]['name'];
        $this->logs[] = ['name' => 'binlog.' . sprintf('%06d', (int) substr($last, -6) + 1), 'size' => 158];
    }

    public function binaryLogs(): array
    {
        return $this->logs;
    }

    public function status(): array
    {
        return ['file' => $this->logs[count($this->logs) - 1]['name'], 'position' => 158, 'gtid_executed' => ''];
    }

    public function grants(): array
    {
        return [];
    }
}
