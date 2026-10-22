<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Agent;

use CzAiAgent\Agent\ClarificationRequiredException;
use CzAiAgent\Agent\QueryIntentGenerator;
use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryIntentGeneratorTest extends TestCase
{
    public function testGeneratesIntentUsingQualifiedCatalogFieldsAndPreservesIdentifiers(): void
    {
        $llm = new class implements LlmAdapter {
            public ?LlmRequest $request = null;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->request = $request;

                return new LlmResponse(
                    content: '{"select":["n_policy_premium_figures.base_premium"],"filters":[{"field":"n_policy_periods.policy_id","operator":"=","value":367838},{"field":"n_policy_premium_figures.period_id","operator":"=","value":723752}]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'give me the base_premium of policy_id 367838 period_id 723752',
            $this->context(),
        );

        self::assertSame(
            'n_policy_premium_figures.base_premium',
            $intent['select'][0],
        );
        self::assertSame('367838', $intent['filters'][0]['value']);
        self::assertNotNull($llm->request);
        self::assertStringContainsString(
            'n_policy_premium_figures.base_premium',
            $llm->request->userMessage,
        );
    }

    public function testAddsDeterministicFiltersWhenTheModelOmitsIdentifiers(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"select":["n_policy_premium_figures.base_premium"],"filters":[{"field":"n_policy_periods.policy_id","operator":"=","value":367838}]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'give me base_premium for policy_id 367838 period_id 723752',
            $this->context(),
        );

        self::assertSame([
            [
                'field' => 'n_policies.id',
                'operator' => '=',
                'value' => '367838',
            ],
            [
                'field' => 'n_policy_periods.id',
                'operator' => '=',
                'value' => '723752',
            ],
        ], $intent['filters']);
    }

    public function testCorrectsIdentifierValuesAssignedToTheWrongFields(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"select":["n_policy_premium_figures.base_premium"],"filters":[{"field":"n_policy_periods.policy_id","operator":"=","value":723752},{"field":"n_policy_premium_figures.period_id","operator":"=","value":367838}]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'base_premium for policy_id 367838 period_id 723752',
            $this->context(),
        );

        self::assertSame([
            [
                'field' => 'n_policies.id',
                'operator' => '=',
                'value' => '367838',
            ],
            [
                'field' => 'n_policy_periods.id',
                'operator' => '=',
                'value' => '723752',
            ],
        ], $intent['filters']);
    }

    public function testResolvesUnqualifiedAlphanumericPolicyToPolicyNumber(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_premium_figures.base_premium"]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show me the premium of policy LP123456',
            $this->context(),
        );

        self::assertSame([
            [
                'field' => 'n_policies.policy_number',
                'operator' => '=',
                'value' => 'LP123456',
            ],
        ], $intent['filters']);
    }

    public function testAsksForClarificationForUnqualifiedNumericPolicyReference(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                self::fail('Ambiguous policy reference should be resolved before calling the LLM.');
            }
        };

        $this->expectException(ClarificationRequiredException::class);
        $this->expectExceptionMessage('policy ID or a policy number');

        (new QueryIntentGenerator($llm))->generateIntent(
            'show me the premium of policy 14535342',
            $this->context(),
        );
    }

    public function testAnchorsCurrentPeriodMeaningAndFiltersNaturalPolicyId(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_periods.start_date"]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show me the current period for policy id 14535342',
            $this->context(),
        );

        self::assertContains(
            'n_policies.current_period_id',
            $intent['select'],
        );
        self::assertContains('n_policy_periods.start_date', $intent['select']);
        self::assertSame([
            [
                'field' => 'n_policies.id',
                'operator' => '=',
                'value' => '14535342',
            ],
        ], $intent['filters']);
    }

    public function testResolvesAnUnqualifiedAlphanumericClaimToClaimNumber(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_claims.claim_number"]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show me claim CLM123456',
            $this->context(),
        );

        self::assertSame([
            [
                'field' => 'n_policy_claims.claim_number',
                'operator' => '=',
                'value' => 'CLM123456',
            ],
        ], $intent['filters']);
    }

    public function testResolvesExplicitCustomerIdentifierOnlyToCatalogCanonicalField(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policies.policy_number"]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show policies for customer id 00071',
            $this->contextWithCustomerId(),
        );

        self::assertSame([
            [
                'field' => 'n_customers.id',
                'operator' => '=',
                'value' => '00071',
            ],
        ], $intent['filters']);
    }

    public function testRequestsClarificationWhenCustomerIdentifierIsNotVerifiedInCatalog(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                self::fail('An unverified customer identifier must not be sent to the model.');
            }
        };

        $this->expectException(ClarificationRequiredException::class);
        $this->expectExceptionMessage('no verified field for customer_id');

        (new QueryIntentGenerator($llm))->generateIntent(
            'show policies for customer id 71',
            $this->context(),
        );
    }

    public function testRequiresSupportedOrderingForLatestQuestions(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"select":["n_policy_periods.policy_id"]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latest or last record require an explicit supported sort field');

        (new QueryIntentGenerator($llm))->generateIntent(
            'show the latest policy period',
            $this->context(),
        );
    }

    public function testAcceptsLatestIntentOrderedByAQualifiedDateField(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"select":["n_policy_periods.policy_id"],"order_by":[{"field":"n_policy_periods.start_date","direction":"DESC"}]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show the latest policy period',
            $this->context(),
        );

        self::assertSame(
            'n_policy_periods.start_date',
            $intent['order_by'][0]['field'],
        );
        self::assertSame(1, $intent['limit']);
    }

    public function testUsesExplicitCountForLatestResults(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_periods.policy_id"],"order_by":[{"field":"n_policy_periods.start_date","direction":"DESC"}]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show the latest 5 policy periods',
            $this->context(),
        );

        self::assertSame(5, $intent['limit']);
    }

    public function testRejectsAscendingSortForLatestQuestion(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_periods.policy_id"],"order_by":[{"field":"n_policy_periods.start_date","direction":"ASC"}]}',
                    'test',
                    'test-model',
                );
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must be ordered first by a supported date or sequence field in descending order'
        );

        (new QueryIntentGenerator($llm))->generateIntent(
            'show the latest policy period',
            $this->context(),
        );
    }

    public function testRequiresThePrimarySortToBeTheDescendingTemporalField(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_periods.policy_id"],"order_by":[{"field":"n_policy_periods.policy_id","direction":"DESC"},{"field":"n_policy_periods.start_date","direction":"DESC"}]}',
                    'test',
                    'test-model',
                );
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must be ordered first by a supported date or sequence field in descending order'
        );

        (new QueryIntentGenerator($llm))->generateIntent(
            'show the latest policy period',
            $this->context(),
        );
    }

    public function testPreservesLeadingZerosInNumericIdentifiers(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    '{"select":["n_policy_periods.policy_id"]}',
                    'test',
                    'test-model',
                );
            }
        };

        $intent = (new QueryIntentGenerator($llm))->generateIntent(
            'show policy id 0014535342',
            $this->context(),
        );

        self::assertSame('0014535342', $intent['filters'][0]['value']);
    }

    public function testRequiresGroupedAggregationForCommonalityQuestions(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"select":["n_policy_periods.policy_id"]}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Pattern and commonality questions require grouped fields and at least one aggregation.'
        );

        (new QueryIntentGenerator($llm))->generateIntent(
            'identify commonalities across policies',
            $this->context(),
        );
    }

    public function testSurfacesClarificationRequests(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"error":"clarification_required","question":"Which premium amount do you mean?"}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(ClarificationRequiredException::class);
        $this->expectExceptionMessage('Which premium amount');

        (new QueryIntentGenerator($llm))->generateIntent(
            'show me the premium',
            $this->context(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'field_candidates' => [
                [
                    'id' => 'n_policy_premium_figures.base_premium',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'base_premium',
                    'data_type' => 'decimal',
                ],
                [
                    'id' => 'n_policy_premium_figures.period_id',
                    'table' => 'n_policy_premium_figures',
                    'column' => 'period_id',
                    'data_type' => 'bigint',
                    'concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.policy_id',
                    'table' => 'n_policy_periods',
                    'column' => 'policy_id',
                    'data_type' => 'bigint',
                    'concepts' => ['policy_id'],
                ],
                [
                    'id' => 'n_policies.id',
                    'table' => 'n_policies',
                    'column' => 'id',
                    'data_type' => 'bigint',
                    'concepts' => ['policy_id'],
                    'canonical_concepts' => ['policy_id'],
                ],
                [
                    'id' => 'n_policies.policy_number',
                    'table' => 'n_policies',
                    'column' => 'policy_number',
                    'data_type' => 'varchar',
                    'concepts' => ['policy_number'],
                    'canonical_concepts' => ['policy_number'],
                ],
                [
                    'id' => 'n_policies.current_period_id',
                    'table' => 'n_policies',
                    'column' => 'current_period_id',
                    'data_type' => 'bigint',
                    'concepts' => ['current_period_id'],
                    'canonical_concepts' => ['current_period_id'],
                ],
                [
                    'id' => 'n_policy_claims.id',
                    'table' => 'n_policy_claims',
                    'column' => 'id',
                    'data_type' => 'bigint',
                    'concepts' => ['claim_id'],
                    'canonical_concepts' => ['claim_id'],
                ],
                [
                    'id' => 'n_policy_claims.claim_number',
                    'table' => 'n_policy_claims',
                    'column' => 'claim_number',
                    'data_type' => 'varchar',
                    'concepts' => ['claim_number'],
                    'canonical_concepts' => ['claim_number'],
                ],
                [
                    'id' => 'n_policy_periods.id',
                    'table' => 'n_policy_periods',
                    'column' => 'id',
                    'data_type' => 'bigint',
                    'concepts' => ['period_id'],
                    'canonical_concepts' => ['period_id'],
                ],
                [
                    'id' => 'n_policy_periods.start_date',
                    'table' => 'n_policy_periods',
                    'column' => 'start_date',
                    'data_type' => 'date',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextWithCustomerId(): array
    {
        $context = $this->context();
        $context['field_candidates'][] = [
            'id' => 'n_customers.id',
            'table' => 'n_customers',
            'column' => 'id',
            'data_type' => 'bigint',
            'concepts' => ['customer_id'],
            'canonical_concepts' => ['customer_id'],
        ];

        return $context;
    }
}
