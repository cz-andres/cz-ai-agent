<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\DataProfilingAdapter;
use CzAiAgent\Database\RelationshipProfilingService;
use PHPUnit\Framework\TestCase;

final class RelationshipProfilingServiceTest extends TestCase
{
    public function testProfilesCandidatesAndPreservesCandidateMetadata(): void
    {
        $candidate = [
            'source_table' => 'n_policy_periods',
            'source_column' => 'policy_id',
            'target_table' => 'n_policies',
            'target_column' => 'id',
            'origin' => 'inferred',
        ];

        $expectedProfile = [
            'source_table' => 'n_policy_periods',
            'source_column' => 'policy_id',
            'target_table' => 'n_policies',
            'target_column' => 'id',
            'sampled_value_count' => 100,
            'matched_value_count' => 100,
            'match_rate' => 1.0,
            'status' => 'profiled',
        ];

        $adapter = new class($expectedProfile) implements DataProfilingAdapter {
            public function __construct(
                private readonly array $profile,
            ) {
            }

            public function profileRelationship(
                array $candidate,
                array $schema,
                int $sampleLimit = 500,
            ): array {
                TestCase::assertSame(100, $sampleLimit);

                return $this->profile;
            }
        };

        $service = new RelationshipProfilingService($adapter);

        $results = $service->profileCandidates(
            [$candidate],
            [
                'database' => 'test_database',
                'tables' => [],
                'columns' => [],
                'foreign_keys' => [],
            ],
            100,
        );

        self::assertCount(1, $results);
        self::assertSame($candidate, $results[0]['candidate']);
        self::assertSame($expectedProfile, $results[0]['profiling']);
        self::assertSame('inferred', $results[0]['candidate']['origin']);
    }

    public function testRejectsCandidateMissingRequiredFields(): void
    {
        $adapter = new class implements DataProfilingAdapter {
            public function profileRelationship(
                array $candidate,
                array $schema,
                int $sampleLimit = 500,
            ): array {
                self::fail('The adapter must not be called for an invalid candidate.');
            }
        };

        $service = new RelationshipProfilingService($adapter);

        $this->expectException(\InvalidArgumentException::class);

        $service->profileCandidates(
            [['source_table' => 'n_policy_periods']],
            [
                'database' => 'test_database',
                'tables' => [],
                'columns' => [],
                'foreign_keys' => [],
            ],
        );
    }
}