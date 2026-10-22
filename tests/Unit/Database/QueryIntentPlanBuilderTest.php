<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Query\QueryIntentPlanBuilder;
use CzAiAgent\Database\Query\ReadOnlyQueryExecutor;
use CzAiAgent\Database\Query\ReadOnlyQueryPlanCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PDO;

final class QueryIntentPlanBuilderTest extends TestCase
{
    public function testBuildsDeclaredJoinAndPreservesCrossTableFilters(): void
    {
        $builder = new QueryIntentPlanBuilder();
        $plan = $builder->build([
            'select' => ['n_policy_premium_figures.base_premium'],
            'filters' => [
                [
                    'field' => 'n_policy_periods.policy_id',
                    'operator' => '=',
                    'value' => 367838,
                ],
                [
                    'field' => 'n_policy_premium_figures.period_id',
                    'operator' => '=',
                    'value' => 723752,
                ],
            ],
        ], $this->context(), $this->schema());

        self::assertSame(
            [
                ['table' => 't0', 'column' => 'base_premium'],
            ],
            $plan['select'],
        );
        self::assertSame(
            [
                'type' => 'INNER',
                'table' => 'n_policy_periods',
                'alias' => 't1',
                'on' => [[
                    'left' => ['table' => 't0', 'column' => 'period_id'],
                    'operator' => '=',
                    'right' => ['table' => 't1', 'column' => 'id'],
                ]],
            ],
            $plan['joins'][0],
        );
        self::assertSame([
            [
                'column' => ['table' => 't1', 'column' => 'policy_id'],
                'operator' => '=',
                'value' => 367838,
            ],
            [
                'column' => ['table' => 't0', 'column' => 'period_id'],
                'operator' => '=',
                'value' => 723752,
            ],
        ], $plan['where']);
        self::assertSame(25, $plan['limit']);
    }

    public function testExecutesPolicyPremiumIntentAgainstSyntheticSqliteTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE n_policy_periods (id INTEGER PRIMARY KEY, policy_id INTEGER)'
        );
        $pdo->exec(
            'CREATE TABLE n_policy_premium_figures (period_id INTEGER, base_premium REAL)'
        );
        $pdo->exec(
            'INSERT INTO n_policy_periods (id, policy_id) VALUES (723752, 367838)'
        );
        $pdo->exec(
            'INSERT INTO n_policy_premium_figures (period_id, base_premium) VALUES (723752, 1250.50)'
        );

        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => ['n_policy_premium_figures.base_premium'],
            'filters' => [
                [
                    'field' => 'n_policy_periods.policy_id',
                    'operator' => '=',
                    'value' => 367838,
                ],
                [
                    'field' => 'n_policy_premium_figures.period_id',
                    'operator' => '=',
                    'value' => 723752,
                ],
            ],
        ], $this->context(), $this->schema());

        $result = (new ReadOnlyQueryExecutor($pdo))->execute(
            $plan,
            $this->schema(),
        );

        self::assertSame(
            [['base_premium' => 1250.5]],
            $result['rows'],
        );
        self::assertSame(
            ['n_policy_premium_figures', 'n_policy_periods'],
            $result['evidence']['tables'],
        );
    }

    public function testExecutesPolicyNumberLookupAndReturnsEmptyEvidenceWhenNotFound(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE n_policies (id INTEGER PRIMARY KEY, policy_number TEXT)'
        );
        $pdo->exec(
            'CREATE TABLE n_policy_periods (id INTEGER PRIMARY KEY, policy_id INTEGER)'
        );
        $pdo->exec(
            'CREATE TABLE n_policy_premium_figures (period_id INTEGER, base_premium REAL)'
        );
        $pdo->exec(
            "INSERT INTO n_policies (id, policy_number) VALUES (367838, 'LP123456')"
        );
        $pdo->exec(
            'INSERT INTO n_policy_periods (id, policy_id) VALUES (723752, 367838)'
        );
        $pdo->exec(
            'INSERT INTO n_policy_premium_figures (period_id, base_premium) VALUES (723752, 1250.50)'
        );

        $schema = [
            'tables' => [
                ['name' => 'n_policies'],
                ['name' => 'n_policy_periods'],
                ['name' => 'n_policy_premium_figures'],
            ],
            'columns' => [
                ['table_name' => 'n_policies', 'name' => 'id'],
                ['table_name' => 'n_policies', 'name' => 'policy_number'],
                ['table_name' => 'n_policy_periods', 'name' => 'id'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'period_id'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'base_premium'],
            ],
            'foreign_keys' => [
                $this->foreignKey(
                    'n_policy_periods',
                    'policy_id',
                    'n_policies',
                    'id',
                ),
                $this->foreignKey(
                    'n_policy_premium_figures',
                    'period_id',
                    'n_policy_periods',
                    'id',
                ),
            ],
        ];
        $context = [
            'field_candidates' => [
                [
                    'id' => 'n_policies.policy_number',
                    'table' => 'n_policies',
                    'column' => 'policy_number',
                    'concepts' => ['policy_number'],
                    'canonical_concepts' => ['policy_number'],
                ],
                [
                    'id' => 'n_policy_premium_figures.base_premium',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'base_premium',
                ],
                [
                    'id' => 'n_policy_premium_figures.period_id',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'period_id',
                    'concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.id',
                    'table' => 'n_policy_periods',
                    'column' => 'id',
                    'concepts' => ['period_id'],
                    'canonical_concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.policy_id',
                    'table' => 'n_policy_periods',
                    'column' => 'policy_id',
                    'concepts' => ['policy_id'],
                ],
                [
                    'id' => 'n_policies.id',
                    'table' => 'n_policies',
                    'column' => 'id',
                    'concepts' => ['policy_id'],
                    'canonical_concepts' => ['policy_id'],
                ],
            ],
        ];
        $builder = new QueryIntentPlanBuilder();
        $executor = new ReadOnlyQueryExecutor($pdo);

        $plan = $builder->build([
            'select' => ['n_policy_premium_figures.base_premium'],
            'filters' => [[
                'field' => 'n_policies.policy_number',
                'operator' => '=',
                'value' => 'LP123456',
            ]],
        ], $context, $schema);

        $result = $executor->execute($plan, $schema);
        self::assertSame(
            [['base_premium' => 1250.5]],
            $result['rows'],
        );
        self::assertSame(
            [
                'n_policy_premium_figures',
                'n_policy_periods',
                'n_policies',
            ],
            $result['evidence']['tables'],
        );

        $notFoundPlan = $builder->build([
            'select' => ['n_policy_premium_figures.base_premium'],
            'filters' => [[
                'field' => 'n_policies.policy_number',
                'operator' => '=',
                'value' => 'LP999999',
            ]],
        ], $context, $schema);
        $notFoundResult = $executor->execute($notFoundPlan, $schema);

        self::assertSame([], $notFoundResult['rows']);
        self::assertSame(0, $notFoundResult['evidence']['returned_rows']);
    }

    public function testRejectsEqualLengthAmbiguousRelationshipPaths(): void
    {
        $schema = [
            'tables' => [
                ['name' => 'requested_values'],
                ['name' => 'filter_values'],
                ['name' => 'bridge_one'],
                ['name' => 'bridge_two'],
            ],
            'columns' => [
                ['table_name' => 'requested_values', 'name' => 'value'],
                ['table_name' => 'filter_values', 'name' => 'id'],
                ['table_name' => 'bridge_one', 'name' => 'requested_id'],
                ['table_name' => 'bridge_one', 'name' => 'filter_id'],
                ['table_name' => 'bridge_two', 'name' => 'requested_id'],
                ['table_name' => 'bridge_two', 'name' => 'filter_id'],
            ],
            'foreign_keys' => [
                $this->foreignKey('requested_values', 'id', 'bridge_one', 'requested_id'),
                $this->foreignKey('bridge_one', 'filter_id', 'filter_values', 'id'),
                $this->foreignKey('requested_values', 'id', 'bridge_two', 'requested_id'),
                $this->foreignKey('bridge_two', 'filter_id', 'filter_values', 'id'),
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple equally short declared relationship paths');

        (new QueryIntentPlanBuilder())->build([
            'select' => ['requested_values.value'],
            'filters' => [[
                'field' => 'filter_values.id',
                'operator' => '=',
                'value' => 1,
            ]],
        ], [
            'field_candidates' => [
                ['id' => 'requested_values.value', 'table' => 'requested_values', 'column' => 'value'],
                ['id' => 'filter_values.id', 'table' => 'filter_values', 'column' => 'id'],
            ],
        ], $schema);
    }

    public function testBuildsEveryColumnPairForACompositeForeignKey(): void
    {
        $schema = [
            'tables' => [
                ['name' => 'tenant_records'],
                ['name' => 'policy_records'],
            ],
            'columns' => [
                ['table_name' => 'tenant_records', 'name' => 'tenant_id'],
                ['table_name' => 'tenant_records', 'name' => 'region_code'],
                ['table_name' => 'tenant_records', 'name' => 'display_name'],
                ['table_name' => 'policy_records', 'name' => 'tenant_id'],
                ['table_name' => 'policy_records', 'name' => 'tenant_region'],
                ['table_name' => 'policy_records', 'name' => 'policy_status'],
            ],
            'foreign_keys' => [
                [
                    ...$this->foreignKey(
                        'policy_records',
                        'tenant_id',
                        'tenant_records',
                        'tenant_id',
                    ),
                    'name' => 'fk_policy_tenant',
                    'target_schema' => 'test_db',
                    'ordinal_position' => 1,
                ],
                [
                    ...$this->foreignKey(
                        'policy_records',
                        'tenant_region',
                        'tenant_records',
                        'region_code',
                    ),
                    'name' => 'fk_policy_tenant',
                    'target_schema' => 'test_db',
                    'ordinal_position' => 2,
                ],
            ],
        ];

        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => ['tenant_records.display_name'],
            'filters' => [[
                'field' => 'policy_records.policy_status',
                'operator' => '=',
                'value' => 'ACTIVE',
            ]],
        ], [
            'field_candidates' => [
                [
                    'id' => 'tenant_records.display_name',
                    'table' => 'tenant_records',
                    'column' => 'display_name',
                ],
                [
                    'id' => 'policy_records.policy_status',
                    'table' => 'policy_records',
                    'column' => 'policy_status',
                ],
            ],
        ], $schema);

        self::assertSame([
            [
                'left' => ['table' => 't0', 'column' => 'tenant_id'],
                'operator' => '=',
                'right' => ['table' => 't1', 'column' => 'tenant_id'],
            ],
            [
                'left' => ['table' => 't0', 'column' => 'region_code'],
                'operator' => '=',
                'right' => ['table' => 't1', 'column' => 'tenant_region'],
            ],
        ], $plan['joins'][0]['on']);
    }

    public function testPrefersRequiredForeignKeyWhenTablesHaveMultipleDeclaredLinks(): void
    {
        $schema = [
            'tables' => [
                ['name' => 'n_policies'],
                ['name' => 'n_policy_periods'],
            ],
            'columns' => [
                ['table_name' => 'n_policies', 'name' => 'id'],
                ['table_name' => 'n_policies', 'name' => 'current_period_id'],
                ['table_name' => 'n_policy_periods', 'name' => 'id'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id'],
                ['table_name' => 'n_policy_periods', 'name' => 'start_date'],
            ],
            'foreign_keys' => [
                $this->foreignKey(
                    'n_policy_periods',
                    'policy_id',
                    'n_policies',
                    'id',
                ),
                $this->foreignKey(
                    'n_policies',
                    'current_period_id',
                    'n_policy_periods',
                    'id',
                ),
            ],
        ];

        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => [
                'n_policies.current_period_id',
                'n_policy_periods.start_date',
            ],
            'filters' => [[
                'field' => 'n_policies.id',
                'operator' => '=',
                'value' => 14535342,
            ]],
        ], [
            'field_candidates' => [
                [
                    'id' => 'n_policies.current_period_id',
                    'table' => 'n_policies',
                    'column' => 'current_period_id',
                ],
                [
                    'id' => 'n_policy_periods.start_date',
                    'table' => 'n_policy_periods',
                    'column' => 'start_date',
                ],
                [
                    'id' => 'n_policies.id',
                    'table' => 'n_policies',
                    'column' => 'id',
                ],
            ],
        ], $schema);

        self::assertSame(
            [
                'left' => ['table' => 't0', 'column' => 'current_period_id'],
                'operator' => '=',
                'right' => ['table' => 't1', 'column' => 'id'],
            ],
            $plan['joins'][0]['on'][0],
        );

        $policyOwnershipPlan = (new QueryIntentPlanBuilder())->build([
            'select' => ['n_policy_periods.start_date'],
            'filters' => [[
                'field' => 'n_policies.id',
                'operator' => '=',
                'value' => 14535342,
            ]],
        ], [
            'field_candidates' => [
                [
                    'id' => 'n_policy_periods.start_date',
                    'table' => 'n_policy_periods',
                    'column' => 'start_date',
                ],
                [
                    'id' => 'n_policies.id',
                    'table' => 'n_policies',
                    'column' => 'id',
                ],
            ],
        ], $schema);

        self::assertSame(
            [
                'left' => ['table' => 't0', 'column' => 'policy_id'],
                'operator' => '=',
                'right' => ['table' => 't1', 'column' => 'id'],
            ],
            $policyOwnershipPlan['joins'][0]['on'][0],
        );
    }

    public function testUsesPolicyOwnershipPathForPremiumFilteredByPolicyAndPeriodIds(): void
    {
        $schema = [
            'tables' => [
                ['name' => 'n_policies'],
                ['name' => 'n_policy_periods'],
                ['name' => 'n_policy_premium_figures'],
            ],
            'columns' => [
                ['table_name' => 'n_policies', 'name' => 'id'],
                ['table_name' => 'n_policies', 'name' => 'current_period_id'],
                ['table_name' => 'n_policy_periods', 'name' => 'id'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'period_id'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'base_premium'],
            ],
            'foreign_keys' => [
                $this->foreignKey(
                    'n_policy_periods',
                    'policy_id',
                    'n_policies',
                    'id',
                ),
                $this->foreignKey(
                    'n_policies',
                    'current_period_id',
                    'n_policy_periods',
                    'id',
                ),
                $this->foreignKey(
                    'n_policy_premium_figures',
                    'period_id',
                    'n_policy_periods',
                    'id',
                ),
            ],
        ];

        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => ['n_policy_premium_figures.base_premium'],
            'filters' => [
                [
                    'field' => 'n_policies.id',
                    'operator' => '=',
                    'value' => 367838,
                ],
                [
                    'field' => 'n_policy_periods.id',
                    'operator' => '=',
                    'value' => 723752,
                ],
            ],
        ], [
            'field_candidates' => [
                [
                    'id' => 'n_policy_premium_figures.base_premium',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'base_premium',
                ],
                [
                    'id' => 'n_policy_premium_figures.period_id',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'period_id',
                    'concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.id',
                    'table' => 'n_policy_periods',
                    'column' => 'id',
                    'concepts' => ['period_id'],
                    'canonical_concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.policy_id',
                    'table' => 'n_policy_periods',
                    'column' => 'policy_id',
                    'concepts' => ['policy_id'],
                ],
                [
                    'id' => 'n_policies.id',
                    'table' => 'n_policies',
                    'column' => 'id',
                    'concepts' => ['policy_id'],
                    'canonical_concepts' => ['policy_id'],
                ],
                [
                    'id' => 'n_policies.current_period_id',
                    'table' => 'n_policies',
                    'column' => 'current_period_id',
                    'concepts' => ['current_period_id'],
                    'canonical_concepts' => ['current_period_id'],
                ],
            ],
        ], $schema);

        self::assertSame(
            [
                [
                    'left' => ['table' => 't0', 'column' => 'period_id'],
                    'operator' => '=',
                    'right' => ['table' => 't1', 'column' => 'id'],
                ],
            ],
            $plan['joins'][0]['on'],
        );
        self::assertSame(
            [
                [
                    'left' => ['table' => 't1', 'column' => 'policy_id'],
                    'operator' => '=',
                    'right' => ['table' => 't2', 'column' => 'id'],
                ],
            ],
            $plan['joins'][1]['on'],
        );
    }

    public function testBuildsGroupedPremiumAggregation(): void
    {
        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => ['n_policy_periods.policy_id'],
            'group_by' => ['n_policy_periods.policy_id'],
            'aggregations' => [[
                'function' => 'AVG',
                'field' => 'n_policy_premium_figures.base_premium',
                'alias' => 'average_base_premium',
            ]],
        ], $this->context(), $this->schema());

        $compiled = (new ReadOnlyQueryPlanCompiler())->compile(
            $plan,
            $this->schema(),
        );

        self::assertStringContainsString(
            'GROUP BY `t0`.`policy_id`',
            $compiled['sql'],
        );
        self::assertStringContainsString(
            'AVG(`t1`.`base_premium`) AS `average_base_premium`',
            $compiled['sql'],
        );
    }

    public function testExecutesGroupedPremiumAnalysisOnSyntheticSqliteData(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE n_policy_periods (id INTEGER PRIMARY KEY, policy_id INTEGER)'
        );
        $pdo->exec(
            'CREATE TABLE n_policy_premium_figures (period_id INTEGER, base_premium REAL)'
        );
        $pdo->exec(
            'INSERT INTO n_policy_periods (id, policy_id) VALUES (1, 100), (2, 100), (3, 200)'
        );
        $pdo->exec(
            'INSERT INTO n_policy_premium_figures (period_id, base_premium) VALUES (1, 10), (2, 30), (3, 40)'
        );

        $plan = (new QueryIntentPlanBuilder())->build([
            'select' => ['n_policy_periods.policy_id'],
            'group_by' => ['n_policy_periods.policy_id'],
            'aggregations' => [[
                'function' => 'AVG',
                'field' => 'n_policy_premium_figures.base_premium',
                'alias' => 'average_base_premium',
            ]],
        ], $this->context(), $this->schema());

        $result = (new ReadOnlyQueryExecutor($pdo))->execute(
            $plan,
            $this->schema(),
        );

        self::assertSame(
            [
                ['policy_id' => 100, 'average_base_premium' => 20.0],
                ['policy_id' => 200, 'average_base_premium' => 40.0],
            ],
            $result['rows'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'field_candidates' => [
                [
                    'id' => 'n_policy_premium_figures.base_premium',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'base_premium',
                ],
                [
                    'id' => 'n_policy_premium_figures.period_id',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'period_id',
                ],
                [
                    'id' => 'n_policy_periods.policy_id',
                    'table' => 'n_policy_periods',
                    'column' => 'policy_id',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'tables' => [
                ['name' => 'n_policy_premium_figures'],
                ['name' => 'n_policy_periods'],
            ],
            'columns' => [
                ['table_name' => 'n_policy_premium_figures', 'name' => 'base_premium'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'period_id'],
                ['table_name' => 'n_policy_periods', 'name' => 'id'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id'],
            ],
            'foreign_keys' => [
                $this->foreignKey(
                    'n_policy_premium_figures',
                    'period_id',
                    'n_policy_periods',
                    'id',
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function foreignKey(
        string $sourceTable,
        string $sourceColumn,
        string $targetTable,
        string $targetColumn,
    ): array {
        return [
            'source_table' => $sourceTable,
            'source_column' => $sourceColumn,
            'target_table' => $targetTable,
            'target_column' => $targetColumn,
        ];
    }
}
