<?php
declare(strict_types=1);


namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\ReadOnlyQueryPlanCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReadOnlyQueryPlanCompilerTest extends TestCase
{
    private ReadOnlyQueryPlanCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ReadOnlyQueryPlanCompiler();
    }

    public function testItCompilesAValidatedParameterizedQuery(): void
    {
        $compiled = $this->compiler->compile([
            'select' => [
                ['table' => 'p', 'column' => 'id'],
                ['table' => 'p', 'column' => 'status'],
            ],
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'where' => [
                [
                    'column' => ['table' => 'p', 'column' => 'status'],
                    'operator' => '=',
                    'value' => 'active',
                ],
            ],
            'order_by' => [
                ['table' => 'p', 'column' => 'id', 'direction' => 'DESC'],
            ],
            'limit' => 25,
        ], $this->schema());

        self::assertStringStartsWith('SELECT ', $compiled['sql']);
        self::assertStringContainsString('FROM `policy_records` AS `p`', $compiled['sql']);
        self::assertStringContainsString('WHERE `p`.`status` = ?', $compiled['sql']);
        self::assertStringContainsString('ORDER BY `p`.`id` DESC', $compiled['sql']);
        self::assertStringEndsWith('LIMIT 25', $compiled['sql']);
        self::assertSame(['active'], $compiled['parameters']);
        self::assertStringNotContainsString('active', $compiled['sql']);
    }

    public function testItCompilesAValidatedJoin(): void
    {
        $compiled = $this->compiler->compile([
            'select' => [
                ['table' => 'p', 'column' => 'id'],
                ['table' => 'c', 'column' => 'name'],
            ],
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
            'limit' => 10,
        ], $this->schema());

        self::assertStringContainsString(
            'INNER JOIN `customer_records` AS `c`',
            $compiled['sql']
        );
        self::assertStringContainsString(
            '`p`.`customer_id` = `c`.`id`',
            $compiled['sql']
        );
    }

    public function testItRejectsUnknownTables(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile([
            'select' => [['table' => 'x', 'column' => 'id']],
            'from' => ['table' => 'unknown_records', 'alias' => 'x'],
            'limit' => 10,
        ], $this->schema());
    }

    public function testItRejectsUnknownColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile([
            'select' => [['table' => 'p', 'column' => 'secret_unknown_column']],
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'limit' => 10,
        ], $this->schema());
    }

    public function testItRejectsUnsupportedOperators(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile([
            'select' => [['table' => 'p', 'column' => 'id']],
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'where' => [
                [
                    'column' => ['table' => 'p', 'column' => 'status'],
                    'operator' => 'OR 1=1',
                    'value' => 'active',
                ],
            ],
            'limit' => 10,
        ], $this->schema());
    }

    public function testItRejectsExcessiveLimits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile([
            'select' => [['table' => 'p', 'column' => 'id']],
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'limit' => 101,
        ], $this->schema());
    }

    public function testItRejectsDuplicateAliases(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile([
            'select' => [['table' => 'p', 'column' => 'id']],
            'from' => ['table' => 'policy_records', 'alias' => 'p'],
            'joins' => [
                [
                    'type' => 'LEFT',
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
            'limit' => 10,
        ], $this->schema());
    }

    /**
     * Synthetic fixture only. Production code must use discovered schema.
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
                ['table_name' => 'policy_records', 'name' => 'status'],
                ['table_name' => 'policy_records', 'name' => 'customer_id'],
                ['table_name' => 'customer_records', 'name' => 'id'],
                ['table_name' => 'customer_records', 'name' => 'name'],
            ],
        ];
    }
}