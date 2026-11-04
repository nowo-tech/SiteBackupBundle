<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\UnsafeTableRuleException;
use Nowo\SiteBackupBundle\Model\BackupOptions;

use function array_filter;
use function array_values;
use function implode;
use function in_array;
use function sprintf;

/**
 * Refuses table rules that would silently break a restore (1.28+).
 *
 * | Situation                                       | skip                          | structure only |
 * | ----------------------------------------------- | ----------------------------- | -------------- |
 * | unknown name / a view                           | refused                       | refused        |
 * | required table (host list + migrations table)   | refused (never downgraded)    | refused        |
 * | referenced by a foreign key of a dumped table   | refused (warning when unsafe) | warning        |
 * | read by a view                                  | refused (warning when unsafe) | allowed        |
 *
 * "Unsafe" is {@see BackupOptions::$allowUnsafeTableRules}.
 *
 * Pure: no I/O, no state.
 */
final class TableSafetyChecker
{
    public const DEFAULT_MIGRATIONS_TABLE = 'doctrine_migration_versions';

    /**
     * @param array<string, TableRule> $rules
     * @param list<string> $requiredTables never skipped nor dumped without rows
     *
     * @throws UnsafeTableRuleException on the first refused rule
     *
     * @return list<string> warnings (table names and reasons only)
     */
    public static function check(
        array $rules,
        SchemaSnapshot $schema,
        array $requiredTables = [],
        bool $allowUnsafe = false,
        string $migrationsTable = self::DEFAULT_MIGRATIONS_TABLE,
    ): array {
        $required = [...$requiredTables, $migrationsTable];
        $warnings = [];

        foreach ($rules as $table => $rule) {
            if (!$schema->hasBaseTable($table)) {
                throw new UnsafeTableRuleException($schema->isView($table) ? UnsafeTableRuleException::NOT_A_BASE_TABLE : UnsafeTableRuleException::UNKNOWN_TABLE, $table);
            }

            if (in_array($table, $required, true)) {
                throw new UnsafeTableRuleException(UnsafeTableRuleException::REQUIRED_TABLE, $table);
            }

            // Tables whose CREATE TABLE stays in the dump (any table not skipped) keep their foreign keys.
            $referrers = array_values(array_filter(
                $schema->referencingTables($table),
                static fn (string $referrer): bool => ($rules[$referrer] ?? null) !== TableRule::Skip,
            ));

            if ($rule === TableRule::Skip) {
                if ($referrers !== []) {
                    $warnings[] = self::refuseOrWarn(UnsafeTableRuleException::REFERENCED_BY_FK, $table, $referrers, $allowUnsafe);
                }
                $views = $schema->viewsUsing($table);
                if ($views !== []) {
                    $warnings[] = self::refuseOrWarn(UnsafeTableRuleException::USED_BY_VIEW, $table, $views, $allowUnsafe);
                }
                continue;
            }

            $dataReferrers = array_values(array_filter(
                $referrers,
                static fn (string $referrer): bool => !isset($rules[$referrer]),
            ));
            if ($dataReferrers !== []) {
                $warnings[] = sprintf(
                    'Table "%s" is dumped without rows but rows of %s reference it: they will point at missing ids after a restore.',
                    $table,
                    implode(', ', $dataReferrers),
                );
            }
        }

        return $warnings;
    }

    /**
     * @param list<string> $related
     */
    private static function refuseOrWarn(string $reason, string $table, array $related, bool $allowUnsafe): string
    {
        if (!$allowUnsafe) {
            throw new UnsafeTableRuleException($reason, $table, $related);
        }

        return UnsafeTableRuleException::describe($reason, $table, $related) . ' Allowed by allowUnsafeTableRules.';
    }
}
