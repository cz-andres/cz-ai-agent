<?php
declare(strict_types=1);


namespace CzAiAgent\Database\Query;

use PDO;
use RuntimeException;
use Throwable;

final class ReadOnlyQueryExecutor
{
    private const MAX_ROWS = 100;
    private const MAX_ROW_BYTES = 262144;
    private const MAX_RESULT_BYTES = 1048576;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReadOnlyQueryPlanCompiler $compiler = new ReadOnlyQueryPlanCompiler(),
        private readonly QueryPlanValidator $validator = new QueryPlanValidator(),
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Execute a validated structured query plan.
     *
     * The PDO connection must use a database account restricted to read-only
     * permissions. This class is not a replacement for database permissions.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $schema
     * @return array{
     *     row_count: int,
     *     rows: list<array<string, mixed>>,
     *     evidence: array{
     *         source: string,
     *         tables: list<string>,
     *         returned_rows: int
     *     }
     * }
     */
    public function execute(array $plan, array $schema): array
    {
        try {
            $this->validator->validate($plan, $schema);
            $compiled = $this->compiler->compile($plan, $schema);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException(
                'The proposed query plan failed validation.',
                0,
                $exception,
        );
        }

        try {
            $statement = $this->pdo->prepare($compiled['sql']);
            $statement->execute($compiled['parameters']);


            $rows = [];
            $resultBytes = 0;

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }

                $encodedRow = json_encode(
                    $row,
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
                );

                $rowBytes = strlen($encodedRow);

                if ($rowBytes > self::MAX_ROW_BYTES) {
                    throw new RuntimeException(
                        'A query result row exceeds the permitted size.',
                    );
                }

                if ($resultBytes + $rowBytes > self::MAX_RESULT_BYTES) {
                    throw new RuntimeException(
                        'The query result exceeds the permitted size.',
                    );
                }

                $rows[] = $row;
                $resultBytes += $rowBytes;
            }

            $tableNames = [];

            if (isset($plan['from']['table']) && is_string($plan['from']['table'])) {
                $tableNames[] = $plan['from']['table'];
            }

            foreach ($plan['joins'] ?? [] as $join) {
                if (is_array($join) && is_string($join['table'] ?? null)) {
                    $tableNames[] = $join['table'];
                }
            }

            return [
                'row_count' => count($rows),
                'rows' => array_values($rows),
                'evidence' => [
                    'source' => 'read_only_database_query',
                    'tables' => array_values(array_unique($tableNames)),
                    'returned_rows' => count($rows),
                ],
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The read-only database query could not be completed.',
                0,
                $exception,
            );
        }
    }
}