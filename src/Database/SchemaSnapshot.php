<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use function array_keys;
use function array_values;
use function in_array;
use function sort;
use function str_contains;

/**
 * What the table-rule safety check needs to know about one schema (1.28+): base tables, views, foreign keys and the
 * tables each view reads. Names only, never rows.
 */
final readonly class SchemaSnapshot
{
    /**
     * @param list<string> $baseTables
     * @param list<string> $views
     * @param array<string, list<string>> $referencedBy referenced table => referencing tables (self-references dropped)
     * @param array<string, list<string>> $usedByViews base table => views whose definition reads it
     */
    public function __construct(
        public array $baseTables,
        public array $views = [],
        public array $referencedBy = [],
        public array $usedByViews = [],
    ) {
    }

    /**
     * @param list<string> $baseTables
     * @param list<string> $views
     * @param list<array{0: string, 1: string}> $foreignKeys pairs (referencing table, referenced table)
     * @param array<string, string> $viewDefinitions view => SQL definition (as information_schema returns it)
     */
    public static function fromRows(array $baseTables, array $views, array $foreignKeys, array $viewDefinitions): self
    {
        $referencedBy = [];
        foreach ($foreignKeys as [$from, $to]) {
            if ($from === $to) {
                continue;
            }
            $referencedBy[$to] ??= [];
            if (!in_array($from, $referencedBy[$to], true)) {
                $referencedBy[$to][] = $from;
            }
        }
        foreach (array_keys($referencedBy) as $table) {
            sort($referencedBy[$table]);
        }

        $usedByViews = [];
        foreach ($viewDefinitions as $view => $definition) {
            foreach ($baseTables as $table) {
                // MySQL / MariaDB store view definitions with every identifier back-quoted.
                if (str_contains($definition, '`' . $table . '`')) {
                    $usedByViews[$table][] = $view;
                }
            }
        }

        return new self(array_values($baseTables), array_values($views), $referencedBy, $usedByViews);
    }

    public function hasBaseTable(string $table): bool
    {
        return in_array($table, $this->baseTables, true);
    }

    public function isView(string $name): bool
    {
        return in_array($name, $this->views, true);
    }

    /**
     * @return list<string>
     */
    public function referencingTables(string $table): array
    {
        return $this->referencedBy[$table] ?? [];
    }

    /**
     * @return list<string>
     */
    public function viewsUsing(string $table): array
    {
        return $this->usedByViews[$table] ?? [];
    }
}
