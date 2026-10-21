<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class RelationshipConfidenceScorer
{
    /**
     * @param array<string, mixed> $relationship
     * @return array{
     *     evidence_score: float,
     *     evidence_strength: string,
     *     score_basis: list<string>,
     *     assessment_status: string,
     *     data_profiled: bool
     * }
     */
    public function scoreStructural(array $relationship): array
    {
        if (($relationship['status'] ?? null) === 'declared_foreign_key') {
            return [
                'evidence_score' => 1.0,
                'evidence_strength' => 'declared',
                'score_basis' => ['declared_foreign_key'],
                'assessment_status' => 'declared_foreign_key',
                'data_profiled' => false,
            ];
        }


        $score = 0.0;
        $basis = [];

        if ($relationship['target_column_is_primary_key'] ?? false) {
            $score += 0.25;
            $basis[] = 'target_is_primary_key';
        } elseif ($relationship['target_column_is_unique'] ?? false) {
            $score += 0.20;
            $basis[] = 'target_is_unique';
        }

        if (($relationship['type_compatible'] ?? false) === true) {
            $score += 0.20;
            $basis[] = 'compatible_data_types';
        }

        $evidence = $relationship['evidence'] ?? [];

        if (in_array('column_suffix_matches_target_table', $evidence, true)) {
            $score += 0.30;
            $basis[] = 'column_suffix_matches_target_table';
        }

        if (in_array('column_name_matches_target_key', $evidence, true)) {
            $score += 0.10;
            $basis[] = 'column_name_matches_target_key';
        }

        $ambiguityCount = $relationship['relationship_ambiguity_count'] ?? 1;

        if (is_numeric($ambiguityCount) && (int) $ambiguityCount > 1) {
            $score -= 0.15;
            $basis[] = 'ambiguous_source_column_multiple_targets';
        }

        return $this->buildScore(
            $score,
            $basis,
            'structural_only',
            false,
        );
    }

    /**
     * @param array<string, mixed> $relationship
     * @return array{
     *     evidence_score: float,
     *     evidence_strength: string,
     *     score_basis: list<string>,
     *     assessment_status: string,
     *     data_profiled: bool
     * }
     */
    public function score(array $relationship): array
    {
        $structural = $this->scoreStructural($relationship);

        if (($relationship['status'] ?? null) === 'declared_foreign_key') {
            return $structural;
        }

        $score = $structural['evidence_score'];
        $basis = $structural['score_basis'];
        $profiling = $relationship['profiling'] ?? [];

        $profilingStatus = $profiling['status'] ?? null;

        if (
            $profilingStatus === 'profiled'
            && isset($profiling['match_rate'])
            && is_numeric($profiling['match_rate'])
        ) {
            $matchRate = (float) $profiling['match_rate'];

            if ($matchRate >= 0.0 && $matchRate <= 1.0) {
                $score += (0.15 * $matchRate)
                    - (0.35 * (1.0 - $matchRate));

                $basis[] = $matchRate < 0.5
                    ? 'poor_sampled_data_match_rate'
                    : 'sampled_data_match_rate';

                return $this->buildScore(
                    $score,
                    $basis,
                    'data_profiled',
                    true,
                );
            }
        }

        $assessmentStatus = match ($profilingStatus) {
            'profiled' => 'profiling_inconclusive',
            'skipped_candidate_limit' => 'structural_only',
            default => 'structural_only',
        };

        return $this->buildScore(
            $structural['evidence_score'],
            $basis,
            $assessmentStatus,
            false,
        );
    }

    /**
     * @param list<string> $basis
     * @return array{
     *     evidence_score: float,
     *     evidence_strength: string,
     *     score_basis: list<string>,
     *     assessment_status: string,
     *     data_profiled: bool
     * }
     */
    private function buildScore(
        float $score,
        array $basis,
        string $assessmentStatus,
        bool $dataProfiled,
    ): array {
        $score = round(min(1.0, max(0.0, $score)), 4);

        $strength = match (true) {
            $score >= 0.75 => 'high',
            $score >= 0.50 => 'medium',
            default => 'low',
        };

        return [
            'evidence_score' => $score,
            'evidence_strength' => $strength,
            'score_basis' => $basis,
            'assessment_status' => $assessmentStatus,
            'data_profiled' => $dataProfiled,
        ];
    }
}
