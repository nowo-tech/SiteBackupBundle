<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

final class FakeDbalResult
{
    /**
     * @param array<string, mixed>|null $row
     * @param list<array<string, mixed>> $all
     */
    public function __construct(
        private readonly ?array $row,
        private readonly array $all = [],
    ) {
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchAssociative(): array|false
    {
        return $this->row ?? false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllAssociative(): array
    {
        if ($this->all !== []) {
            return $this->all;
        }

        return $this->row !== null ? [$this->row] : [];
    }
}
