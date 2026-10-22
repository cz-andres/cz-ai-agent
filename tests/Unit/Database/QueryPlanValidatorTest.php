<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Query\QueryPlanValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryPlanValidatorTest extends TestCase
{
    private QueryPlanValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QueryPlanValidator();
    }

    public function testAcceptsAJoinMatchingADeclaredForeignKey(): void
    {
        $this->validator->validate([
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'customer_records',
                    'alias' => 'c',
                    'on' => [
                        [
                            'left' => ['table' => 'p', 'column' => 'customer_id'],
                            'operator' => '=',
                            'right' => ['table' => 'c', 'column' => 'id'],
                        ],
                    ],
                ],
            ],
        ], $this->schema());

        self::assertTrue(true);
    }

    public function testRejectsJoinWithoutDeclaredRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'does not match a declared foreign-key relationship'
        );

        $this->validator->validate([
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'customer_records',
                    'alias' => 'c',
                    'on' => [
                        [
                            'left' => ['table' => 'p', 'column' => 'id'],
                            'operator' => '=',
                            'right' => ['table' => 'c', 'column' => 'id'],
                        ],
                    ],
                ],
            ],
        ], $this->schema());
    }

    public function testRejectsDisconnectedJoinCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'references an alias that is not yet connected'
        );

        $this->validator->validate([
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'customer_records',
                    'alias' => 'c',
                    'on' => [
                        [
                            'left' => ['table' => 'c', 'column' => 'id'],
                            'operator' => '=',
                            'right' => ['table' => 'unknown_alias', 'column' => 'id'],
                        ],
                    ],
                ],
            ],
        ], $this->schema());
    }

    public function testRejectsJoinWithUnrelatedAdditionalCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A join condition does not match a declared foreign-key relationship.'
        );

        $this->validator->validate([
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'customer_records',
                    'alias' => 'c',
                    'on' => [
                        [
                            'left' => ['table' => 'p', 'column' => 'customer_id'],
                            'operator' => '=',
                            'right' => ['table' => 'c', 'column' => 'id'],
                        ],
                        [
                            'left' => ['table' => 'p', 'column' => 'id'],
                            'operator' => '=',
                            'right' => ['table' => 'c', 'column' => 'id'],
                        ],
                    ],
                ],
            ],
        ], $this->schema());
    }

    public function testRejectsPartialCompositeForeignKeyJoin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must include every column pair from its declared foreign-key constraint'
        );

        $this->validator->validate([
            'from' => ['table' => 'tenant_records', 'alias' => 't'],
            'joins' => [[
                'type' => 'INNER',
                'table' => 'policy_records',
                'alias' => 'p',
                'on' => [[
                    'left' => ['table' => 'p', 'column' => 'tenant_id'],
                    'operator' => '=',
                    'right' => ['table' => 't', 'column' => 'tenant_id'],
                ]],
            ]],
        ], $this->compositeForeignKeySchema());
    }

    public function testAcceptsCompleteCompositeForeignKeyJoin(): void
    {
        $this->validator->validate([
            'from' => ['table' => 'tenant_records', 'alias' => 't'],
            'joins' => [[
                'type' => 'INNER',
                'table' => 'policy_records',
                'alias' => 'p',
                'on' => [
                    [
                        'left' => ['table' => 'p', 'column' => 'tenant_id'],
                        'operator' => '=',
                        'right' => ['table' => 't', 'column' => 'tenant_id'],
                    ],
                    [
                        'left' => ['table' => 'p', 'column' => 'tenant_region'],
                        'operator' => '=',
                        'right' => ['table' => 't', 'column' => 'region_code'],
                    ],
                ],
            ]],
        ], $this->compositeForeignKeySchema());

        self::assertTrue(true);
    }

    public function testRejectsDuplicateAliases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table aliases must be unique.');

        $this->validator->validate([
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'customer_records',
                    'alias' => 'p',
                    'on' => [
                        [
                            'left' => ['table' => 'p', 'column' => 'customer_id'],
                            'operator' => '=',
                            'right' => ['table' => 'p', 'column' => 'id'],
                        ],
                    ],
                ],
            ],
        ], $this->schema());
    }

    /**
     * Synthetic fixture only. Production validation uses discovered metadata.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'database' => 'synthetic_test_database',
            'tables' => [
                ['name' => 'policy_records'],
                ['name' => 'customer_records'],
            ],
            'columns' => [
                ['table_name' => 'policy_records', 'name' => 'id'],
                ['table_name' => 'policy_records', 'name' => 'customer_id'],
                ['table_name' => 'customer_records', 'name' => 'id'],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_policy_customer',
                    'source_table' => 'policy_records',
                    'source_column' => 'customer_id',
                    'target_schema' => 'synthetic_test_database',
                    'target_table' => 'customer_records',
                    'target_column' => 'id',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compositeForeignKeySchema(): array
    {
        return [
            'tables' => [
                ['name' => 'tenant_records'],
                ['name' => 'policy_records'],
            ],
            'columns' => [
                ['table_name' => 'tenant_records', 'name' => 'tenant_id'],
                ['table_name' => 'tenant_records', 'name' => 'region_code'],
                ['table_name' => 'policy_records', 'name' => 'tenant_id'],
                ['table_name' => 'policy_records', 'name' => 'tenant_region'],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_policy_tenant',
                    'ordinal_position' => 1,
                    'source_table' => 'policy_records',
                    'source_column' => 'tenant_id',
                    'target_table' => 'tenant_records',
                    'target_column' => 'tenant_id',
                ],
                [
                    'name' => 'fk_policy_tenant',
                    'ordinal_position' => 2,
                    'source_table' => 'policy_records',
                    'source_column' => 'tenant_region',
                    'target_table' => 'tenant_records',
                    'target_column' => 'region_code',
                ],
            ],
        ];
    }
}
