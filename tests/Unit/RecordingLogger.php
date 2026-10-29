<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;

/**
 * PSR-3 logger that keeps every record (level, message, context) for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string> "level message" lines
     */
    public function lines(): array
    {
        return array_map(static fn (array $r): string => $r['level'] . ' ' . $r['message'], $this->records);
    }
}
