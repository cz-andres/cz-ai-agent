<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Profiling\RelationshipProfilingDiagnostics;
use PHPUnit\Framework\TestCase;

final class RelationshipProfilingDiagnosticsTest extends TestCase
{
    public function testClassifiesHighOverlapWithLargeSample(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $result = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 100,
            'match_rate' => 0.95,
        ]);

        self::assertSame('high_overlap', $result['classification']);
        self::assertSame(['high_overlap'], $result['classifications']);
        self::assertSame(100, $result['sample_size']);
        self::assertSame(0.95, $result['match_rate']);
    }

    public function testFlagsSmallSampleEvenWhenOverlapIsHigh(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $result = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 6,
            'match_rate' => 1.0,
        ]);

        self::assertSame('high_overlap', $result['classification']);
        self::assertSame(
            ['high_overlap', 'small_sample'],
            $result['classifications'],
        );
    }

    public function testClassifiesLowAndPartialOverlap(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $low = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 100,
            'match_rate' => 0.49,
        ]);

        $partial = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 100,
            'match_rate' => 0.79,
        ]);

        self::assertSame('low_overlap', $low['classification']);
        self::assertSame('partial_overlap', $partial['classification']);
    }

    public function testClassifiesProfiledResultWithoutValidRateAsInconclusive(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $result = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 10,
            'match_rate' => null,
        ]);

        self::assertSame(
            'profiling_inconclusive',
            $result['classification'],
        );
        self::assertSame(
            ['profiling_inconclusive'],
            $result['classifications'],
        );
        self::assertNull($result['match_rate']);
    }

    public function testClassifiesSkippedCandidateAsNotProfiled(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $result = $diagnostics->analyze([
            'status' => 'skipped_candidate_limit',
            'sampled_value_count' => 0,
            'match_rate' => null,
        ]);

        self::assertSame('not_profiled', $result['classification']);
        self::assertSame(['not_profiled'], $result['classifications']);
    }

    public function testRejectsOutOfRangeMatchRateAsInconclusive(): void
    {
        $diagnostics = new RelationshipProfilingDiagnostics();

        $result = $diagnostics->analyze([
            'status' => 'profiled',
            'sampled_value_count' => 100,
            'match_rate' => 1.2,
        ]);

        self::assertSame(
            'profiling_inconclusive',
            $result['classification'],
        );
    }
}
