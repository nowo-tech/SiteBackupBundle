<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\PanelSessionInterface;

/**
 * In-memory panel session counting id regenerations. No Symfony import.
 */
final class RecordingPanelSession implements PanelSessionInterface
{
    public int $regenerated = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerateId(): void
    {
        ++$this->regenerated;
    }
}
