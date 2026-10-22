<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Query\ReadOnlyQueryExecutor;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadOnlyQueryExecutorTest extends TestCase
{
    private PDO $pdo;
    private ReadOnlyQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE sample_records (id INTEGER PRIMARY KEY, label TEXT)'
        );

        $insert = $this->pdo->prepare(
            'INSERT INTO sample_records (id, label) VALUES (?, ?)'
        );

        for ($id = 1; $id <= 150; $id++) {
            $insert->execute([$id, 'record-' . $id]);
        }

        $this->executor = new ReadOnlyQueryExecutor($this->pdo);
    }

    public function testReturnsNoMoreThanTheValidatedQueryLimit(): void
    {
        $result = $this->executor->execute([
            'select' => [
                ['table' => 'r', 'column' => 'id'],
                ['table' => 'r', 'column' => 'label'],
            ],
            'from' => [
                'table' => 'sample_records',
                'alias' => 'r',
            ],
            'order_by' => [
                [
                    'table' => 'r',
                    'column' => 'id',
                    'direction' => 'ASC',
                ],
            ],
            'limit' => 100,
        ], $this->schema());

        self::assertCount(100, $result['rows']);
        self::assertSame(100, $result['row_count']);
        self::assertSame(100, $result['evidence']['returned_rows']);
        self::assertSame(1, $result['rows'][0]['id']);
        self::assertSame(100, $result['rows'][99]['id']);
        self::assertSame(
            [
                'source' => 'read_only_database_query',
                'tables' => ['sample_records'],
                'returned_rows' => 100,
            ],
            $result['evidence'],
        );
    }

    public function testReturnsRowsAndEvidenceForANormalResult(): void
    {
        $result = $this->executor->execute([
            'select' => [
                ['table' => 'r', 'column' => 'id'],
                ['table' => 'r', 'column' => 'label'],
            ],
            'from' => [
                'table' => 'sample_records',
                'alias' => 'r',
            ],
            'limit' => 2,
        ], $this->schema());

        self::assertSame(
            [
                ['id' => 1, 'label' => 'record-1'],
                ['id' => 2, 'label' => 'record-2'],
            ],
            $result['rows'],
        );
        self::assertSame(2, $result['row_count']);
        self::assertSame(
            [
                'source' => 'read_only_database_query',
                'tables' => ['sample_records'],
                'returned_rows' => 2,
            ],
            $result['evidence'],
        );
    }

    public function testRejectsARowLargerThan256KiB(): void
    {
        $this->insertRecord(151, str_repeat('x', 262145));

        $this->assertResultRejected(
            $this->recordsAfterId(150),
            'A query result row exceeds the permitted size.',
        );
    }

    public function testRejectsRowsWhoseCombinedSizeExceeds1MiB(): void
    {
        $this->insertRowsWithEncodedSizes([
            220000,
            220000,
            220000,
            220000,
            220000,
        ]);

        $this->assertResultRejected(
            $this->recordsAfterId(150),
            'The query result exceeds the permitted size.',
        );
    }

    public function testResultAtThe1MiBByteBudgetSucceeds(): void
    {
        $this->insertRowsWithEncodedSizes([
            209715,
            209715,
            209715,
            209715,
            209716,
        ]);

        $result = $this->executor->execute(
            $this->recordsAfterId(150),
            $this->schema(),
        );

        self::assertSame(5, $result['row_count']);
        self::assertCount(5, $result['rows']);
        self::assertSame(5, $result['evidence']['returned_rows']);
        self::assertSame(['sample_records'], $result['evidence']['tables']);

        $encodedBytes = array_sum(array_map(
            static fn (array $row): int => strlen(json_encode(
                $row,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            )),
            $result['rows'],
        ));
        self::assertSame(1048576, $encodedBytes);
    }

    public function testEmptyResultReturnsValidEmptyRowsAndEvidence(): void
    {
        $result = $this->executor->execute(
            $this->recordsAfterId(1000),
            $this->schema(),
        );

        self::assertSame(0, $result['row_count']);
        self::assertSame([], $result['rows']);
        self::assertSame(
            [
                'source' => 'read_only_database_query',
                'tables' => ['sample_records'],
                'returned_rows' => 0,
            ],
            $result['evidence'],
        );
    }

    public function testInvalidPlanNeverExecutesSql(): void
    {
        try {
            $this->executor->execute([
                'select' => [
                    ['table' => 'r', 'column' => 'missing_column'],
                ],
                'from' => [
                    'table' => 'sample_records',
                    'alias' => 'r',
                ],
                'limit' => 10,
            ], $this->schema());

            self::fail('Expected the invalid plan to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The proposed query plan failed validation.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            150,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM sample_records'
            )->fetchColumn(),
        );
    }

    /**
     * @param list<int> $encodedSizes
     */
    private function insertRowsWithEncodedSizes(array $encodedSizes): void
    {
        foreach ($encodedSizes as $index => $encodedSize) {
            $id = 151 + $index;
            $emptyLabelBytes = strlen(json_encode(
                ['id' => $id, 'label' => ''],
                JSON_THROW_ON_ERROR,
            ));
            $labelSize = $encodedSize - $emptyLabelBytes;

            self::assertGreaterThan(0, $labelSize);
            $this->insertRecord($id, str_repeat('x', $labelSize));
        }
    }

    private function insertRecord(int $id, string $label): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO sample_records (id, label) VALUES (?, ?)'
        );
        $insert->execute([$id, $label]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordsAfterId(int $id): array
    {
        return [
            'select' => [
                ['table' => 'r', 'column' => 'id'],
                ['table' => 'r', 'column' => 'label'],
            ],
            'from' => [
                'table' => 'sample_records',
                'alias' => 'r',
            ],
            'where' => [
                [
                    'column' => ['table' => 'r', 'column' => 'id'],
                    'operator' => '>',
                    'value' => $id,
                ],
            ],
            'order_by' => [
                [
                    'table' => 'r',
                    'column' => 'id',
                    'direction' => 'ASC',
                ],
            ],
            'limit' => 10,
        ];
    }

    private function assertResultRejected(array $plan, string $causeMessage): void
    {
        try {
            $this->executor->execute($plan, $this->schema());
            self::fail('Expected oversized query result to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The read-only database query could not be completed.',
                $exception->getMessage(),
            );
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame($causeMessage, $exception->getPrevious()->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'database' => 'synthetic_test_database',
            'tables' => [
                ['name' => 'sample_records'],
            ],
            'columns' => [
                ['table_name' => 'sample_records', 'name' => 'id'],
                ['table_name' => 'sample_records', 'name' => 'label'],
            ],
            'foreign_keys' => [],
        ];
    }
}
