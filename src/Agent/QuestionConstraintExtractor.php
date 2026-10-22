<?php
declare(strict_types=1);

namespace CzAiAgent\Agent;

final class QuestionConstraintExtractor
{
    /**
     * Extract entity identifiers that are explicitly labelled in the question.
     *
     * @param array<string, mixed> $schemaContext
     * @return list<array{field: string, concept: string, operator: string, value: string}>
     */
    public function extract(string $question, array $schemaContext): array
    {
        $candidatesByConcept = $this->indexCanonicalCandidates($schemaContext);
        $mentions = [
            ...$this->extractPolicyMentions($question),
            ...$this->extractPeriodMentions($question),
            ...$this->extractClaimMentions($question),
            ...$this->extractCustomerMentions($question),
        ];

        $constraints = [];
        foreach ($mentions as $mention) {
            $fields = $candidatesByConcept[$mention['concept']] ?? [];
            if (count($fields) !== 1) {
                throw new ClarificationRequiredException(
                    $this->clarificationMessage($mention['concept'], $fields)
                );
            }

            $constraints[] = [
                'field' => $fields[0],
                'concept' => $mention['concept'],
                'operator' => '=',
                'value' => $mention['value'],
            ];
        }

        $this->assertNoConflictingConstraints($constraints);

        return $constraints;
    }

    /**
     * @param array<string, mixed> $schemaContext
     * @return array<string, list<string>>
     */
    private function indexCanonicalCandidates(array $schemaContext): array
    {
        $indexed = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (
                !is_array($candidate)
                || !is_string($candidate['id'] ?? null)
                || !is_array($candidate['canonical_concepts'] ?? null)
            ) {
                continue;
            }

            foreach ($candidate['canonical_concepts'] as $concept) {
                if (is_string($concept)) {
                    $indexed[$concept][] = $candidate['id'];
                }
            }
        }

        foreach ($indexed as &$fieldIds) {
            $fieldIds = array_values(array_unique($fieldIds));
        }
        unset($fieldIds);

        return $indexed;
    }

    /**
     * @return list<array{concept: string, value: string}>
     */
    private function extractPolicyMentions(string $question): array
    {
        $mentions = [];
        preg_match_all(
            '/\bpolicy(?:[_\s-]+(id|identifier|number|no\.?))\b\s*(?:is|=|#|:)?\s*([A-Za-z0-9-]*\d[A-Za-z0-9-]*)/i',
            $question,
            $qualified,
            PREG_SET_ORDER,
        );

        foreach ($qualified as $match) {
            $qualifier = strtolower(rtrim($match[1], '.'));
            $mentions[] = [
                'concept' => in_array($qualifier, ['number', 'no'], true)
                    ? 'policy_number'
                    : 'policy_id',
                'value' => $match[2],
            ];
        }

        $withoutQualifiedMentions = preg_replace(
            '/\bpolicy(?:[_\s-]+(?:id|identifier|number|no\.?))\b\s*(?:is|=|#|:)?\s*[A-Za-z0-9-]*\d[A-Za-z0-9-]*/i',
            ' ',
            $question,
        ) ?? $question;
        preg_match_all(
            '/\bpolicy\s+([A-Za-z0-9-]*\d[A-Za-z0-9-]*)\b/i',
            $withoutQualifiedMentions,
            $unqualified,
            PREG_SET_ORDER,
        );

        foreach ($unqualified as $match) {
            $value = $match[1];
            if (preg_match('/[A-Za-z]/', $value) === 1) {
                $mentions[] = [
                    'concept' => 'policy_number',
                    'value' => $value,
                ];
                continue;
            }

            throw new ClarificationRequiredException(
                'Does this numeric policy reference mean a policy ID or a policy number?'
            );
        }

        return $mentions;
    }

    /**
     * @return list<array{concept: string, value: string}>
     */
    private function extractPeriodMentions(string $question): array
    {
        preg_match_all(
            '/\bperiod(?:[_\s-]+(?:id|identifier))\b\s*(?:is|=|#|:)?\s*([A-Za-z0-9-]*\d[A-Za-z0-9-]*)/i',
            $question,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'concept' => 'period_id',
                'value' => $match[1],
            ],
            $matches,
        );
    }

    /**
     * @return list<array{concept: string, value: string}>
     */
    private function extractClaimMentions(string $question): array
    {
        $mentions = [];
        preg_match_all(
            '/\bclaim(?:[_\s-]+(id|identifier|number|no\.?))\b\s*(?:is|=|#|:)?\s*([A-Za-z0-9-]*\d[A-Za-z0-9-]*)/i',
            $question,
            $qualified,
            PREG_SET_ORDER,
        );

        foreach ($qualified as $match) {
            $qualifier = strtolower(rtrim($match[1], '.'));
            $mentions[] = [
                'concept' => in_array($qualifier, ['number', 'no'], true)
                    ? 'claim_number'
                    : 'claim_id',
                'value' => $match[2],
            ];
        }

        $withoutQualifiedMentions = preg_replace(
            '/\bclaim(?:[_\s-]+(?:id|identifier|number|no\.?))\b\s*(?:is|=|#|:)?\s*[A-Za-z0-9-]*\d[A-Za-z0-9-]*/i',
            ' ',
            $question,
        ) ?? $question;
        preg_match_all(
            '/\bclaim\s+([A-Za-z0-9-]*\d[A-Za-z0-9-]*)\b/i',
            $withoutQualifiedMentions,
            $unqualified,
            PREG_SET_ORDER,
        );

        foreach ($unqualified as $match) {
            $value = $match[1];
            if (preg_match('/[A-Za-z]/', $value) === 1) {
                $mentions[] = [
                    'concept' => 'claim_number',
                    'value' => $value,
                ];
                continue;
            }

            throw new ClarificationRequiredException(
                'Does this numeric claim reference mean a claim ID or a claim number?'
            );
        }

        return $mentions;
    }

    /**
     * @return list<array{concept: string, value: string}>
     */
    private function extractCustomerMentions(string $question): array
    {
        preg_match_all(
            '/\b(?:customer|client)(?:[_\s-]+(?:id|identifier))\b\s*(?:is|=|#|:)?\s*([A-Za-z0-9-]*\d[A-Za-z0-9-]*)/i',
            $question,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'concept' => 'customer_id',
                'value' => $match[1],
            ],
            $matches,
        );
    }

    /**
     * @param list<string> $candidateFields
     */
    private function clarificationMessage(string $concept, array $candidateFields): string
    {
        if ($candidateFields === []) {
            return "The schema catalog has no verified field for {$concept}.";
        }

        return "The schema catalog has multiple possible fields for {$concept}: "
            . implode(', ', $candidateFields)
            . '. Please clarify which one you mean.';
    }

    /**
     * @param list<array{field: string, concept: string, operator: string, value: string}> $constraints
     */
    private function assertNoConflictingConstraints(array $constraints): void
    {
        $valuesByConcept = [];
        foreach ($constraints as $constraint) {
            $valuesByConcept[$constraint['concept']][$constraint['value']] = true;
        }

        foreach ($valuesByConcept as $concept => $values) {
            if (count($values) > 1) {
                throw new ClarificationRequiredException(
                    "The question contains multiple values for {$concept}; please ask about one at a time."
                );
            }
        }
    }

}
