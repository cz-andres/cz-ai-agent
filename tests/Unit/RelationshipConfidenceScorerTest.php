<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\RelationshipConfidenceScorer;
use PHPUnit\Framework\TestCase;

final class RelationshipConfidenceScorerTest extends TestCase
{
    public function testScoresStrongInferredRelationship(): void
    {
        $scorer = new RelationshipConfidenceScorer();

        $result = $scorer->score([
            'status' => 'inferred_unverified',
            'target_column_is_primary_key' => true,
            'target_column_is_unique' => true,
            'type_compatible' => true,
            'evidence' => [
                'column_suffix_matches_target_table',
                'column_name_matches_target_key',
            ],
            'profiling' => [
                'status' => 'profiled',
                'match_rate' => 1.0,
            ],
        ]);

        self::assertSame(1.0, $result['evidence_score']);
        self::assertSame('high', $result['evidence_strength']);
        self::assertContains(
            'sampled_data_match_rate',
            $result['score_basis'],
        );
    }

    public function testDeclaredForeignKeyIsClassifiedSeparately(): void
    {
        $scorer = new RelationshipConfidenceScorer();

        $result = $scorer->score([
            'status' => 'declared_foreign_key',
        ]);

        self::assertSame(1.0, $result['evidence_score']);
        self::assertSame('declared', $result['evidence_strength']);
        self::assertSame(
            ['declared_foreign_key'],
            $result['score_basis'],
        );
    }

    public function testIncompatibleTypesDoNotEarnCompatibilityPoints(): void
    {
        $scorer = new RelationshipConfidenceScorer();

        $result = $scorer->score([
            'status' => 'inferred_unverified',
            'target_column_is_primary_key' => true,
            'type_compatible' => false,
            'evidence' => [],
        ]);

        self::assertSame(0.25, $result['evidence_score']);
        self::assertSame('low', $result['evidence_strength']);
        self::assertNotContains(
            'compatible_data_types',
            $result['score_basis'],
        );
    }
}
