<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Database\SchemaSnapshot;
use Nowo\SiteBackupBundle\Database\TableSafetyChecker;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\UnsafeTableRuleException;
use PHPUnit\Framework\TestCase;

use function array_key_first;

final class TableSafetyCheckerTest extends TestCase
{
    private SchemaSnapshot $schema;

    protected function setUp(): void
    {
        $this->schema = SchemaSnapshot::fromRows(
            ['user', 'post', 'comment', 'event_log', 'event_detail', 'cache_items', 'tree', 'doctrine_migration_versions'],
            ['active_users'],
            [['post', 'user'], ['comment', 'post'], ['comment', 'user'], ['comment', 'user'], ['event_detail', 'event_log'], ['tree', 'tree']],
            ['active_users' => 'select `beacon`.`user`.`id` AS `id` from `beacon`.`user`'],
        );
    }

    public function testSnapshotGraph(): void
    {
        self::assertSame(['comment', 'post'], $this->schema->referencingTables('user'));
        self::assertSame([], $this->schema->referencingTables('tree'), 'self references are dropped');
        self::assertSame(['active_users'], $this->schema->viewsUsing('user'));
        self::assertTrue($this->schema->isView('active_users'));
        self::assertFalse($this->schema->hasBaseTable('active_users'));
    }

    public function testSafeRulesPass(): void
    {
        self::assertSame([], TableSafetyChecker::check(['cache_items' => TableRule::Skip, 'tree' => TableRule::Skip], $this->schema));
    }

    public function testSkippingAParentWithItsChildrenIsSafe(): void
    {
        self::assertSame([], TableSafetyChecker::check(['event_log' => TableRule::Skip, 'event_detail' => TableRule::Skip], $this->schema));
    }

    public function testStructureOnlyParentWarnsAboutDataChildren(): void
    {
        $warnings = TableSafetyChecker::check(['event_log' => TableRule::StructureOnly], $this->schema);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('event_detail', $warnings[0]);
        self::assertSame([], TableSafetyChecker::check(['event_log' => TableRule::StructureOnly, 'event_detail' => TableRule::StructureOnly], $this->schema));
    }

    public function testRefusals(): void
    {
        $cases = [
            [['nope' => TableRule::Skip], UnsafeTableRuleException::UNKNOWN_TABLE, []],
            [['active_users' => TableRule::StructureOnly], UnsafeTableRuleException::NOT_A_BASE_TABLE, []],
            [['doctrine_migration_versions' => TableRule::StructureOnly], UnsafeTableRuleException::REQUIRED_TABLE, []],
            [['post' => TableRule::Skip], UnsafeTableRuleException::REQUIRED_TABLE, ['post']],
            [['event_log' => TableRule::Skip], UnsafeTableRuleException::REFERENCED_BY_FK, []],
            [['event_log' => TableRule::Skip, 'event_detail' => TableRule::StructureOnly], UnsafeTableRuleException::REFERENCED_BY_FK, []],
        ];

        foreach ($cases as [$rules, $reason, $required]) {
            try {
                TableSafetyChecker::check($rules, $this->schema, $required);
                self::fail('Expected a refusal: ' . $reason);
            } catch (UnsafeTableRuleException $e) {
                self::assertSame($reason, $e->getReason());
                self::assertSame(array_key_first($rules), $e->getTable());
            }
        }
    }

    public function testViewUsageRefusesSkip(): void
    {
        $schema = SchemaSnapshot::fromRows(['user'], ['v'], [], ['v' => 'select `user`.`id` from `user`']);

        try {
            TableSafetyChecker::check(['user' => TableRule::Skip], $schema);
            self::fail('Expected a refusal');
        } catch (UnsafeTableRuleException $e) {
            self::assertSame(UnsafeTableRuleException::USED_BY_VIEW, $e->getReason());
            self::assertSame(['v'], $e->getRelated());
            self::assertStringContainsString('view(s) v', $e->getMessage());
        }

        self::assertSame([], TableSafetyChecker::check(['user' => TableRule::StructureOnly], $schema));
    }

    public function testUnsafeDowngradesFkAndViewRefusalsOnly(): void
    {
        $warnings = TableSafetyChecker::check(['user' => TableRule::Skip], $this->schema, [], true);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('allowUnsafeTableRules', $warnings[0]);
        self::assertStringContainsString('comment, post', $warnings[0]);

        $this->expectException(UnsafeTableRuleException::class);
        TableSafetyChecker::check(['doctrine_migration_versions' => TableRule::Skip], $this->schema, [], true);
    }

    public function testCustomMigrationsTable(): void
    {
        $schema = SchemaSnapshot::fromRows(['migrations'], [], [], []);

        $this->expectException(UnsafeTableRuleException::class);
        TableSafetyChecker::check(['migrations' => TableRule::Skip], $schema, [], false, 'migrations');
    }

    public function testDescribeFallback(): void
    {
        self::assertSame('Unsafe table rule for "t" (other).', UnsafeTableRuleException::describe('other', 't'));
    }
}
