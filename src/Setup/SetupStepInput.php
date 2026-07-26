<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use function array_key_exists;
use function is_string;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * @param array<string, mixed> $data
 */
final class SetupStepInput
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data = [])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }

        return filter_var($this->data[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
