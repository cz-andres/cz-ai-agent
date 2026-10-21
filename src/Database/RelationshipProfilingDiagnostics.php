<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class RelationshipProfilingDiagnostics
{
    private const SMALL_SAMPLE_THRESHOLD = 30;
    private const LOW_OVERLAP_THRESHOLD = 0.50;
    private const HIGH_OVERLAP_THRESHOLD = 0.80;

    /**
     * Classify profiling evidence without changing the confidence score.
     *
     * @param array<string, mixed> $profiling
     * @return array{
     *     classification: string,
     *     classifications: list<string>,
     *     sample_size: int,
     *     match_rate: float|null
     * }
     */
    public function analyze(array $profiling): array
    {
        $sampleSize = max(0, (int) ($profiling['sampled_value_count'] ?? 0));
        $matchRate = $profiling['match_rate'] ?? null;
        $hasValidMatchRate = is_numeric($matchRate)
            && (float) $matchRate >= 0.0
            && (float) $matchRate <= 1.0;

        if (($profiling['status'] ?? null) !== 'profiled') {
            return [
                'classification' => 'not_profiled',
                'classifications' => ['not_profiled'],
                'sample_size' => $sampleSize,
                'match_rate' => $hasValidMatchRate ? (float) $matchRate : null,
            ];
        }

        if (!$hasValidMatchRate) {
            return [
                'classification' => 'profiling_inconclusive',
                'classifications' => ['profiling_inconclusive'],
                'sample_size' => $sampleSize,
                'match_rate' => null,
            ];
        }

        $rate = (float) $matchRate;

        if ($rate < self::LOW_OVERLAP_THRESHOLD) {
            $classification = 'low_overlap';
        } elseif ($rate < self::HIGH_OVERLAP_THRESHOLD) {
            $classification = 'partial_overlap';
        } else {
            $classification = 'high_overlap';
        }

        $classifications = [$classification];

        if ($sampleSize < self::SMALL_SAMPLE_THRESHOLD) {
            $classifications[] = 'small_sample';
        }

        return [
            'classification' => $classification,
            'classifications' => $classifications,
            'sample_size' => $sampleSize,
            'match_rate' => $rate,
        ];
    }
}
