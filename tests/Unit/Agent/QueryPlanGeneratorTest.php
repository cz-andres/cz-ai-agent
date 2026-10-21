<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Agent;

use CzAiAgent\Agent\QueryPlanGenerator;
use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryPlanGeneratorTest extends TestCase
{
    public function testItParsesAValidStructuredPlan(): void
    {
        $llm = new class implements LlmAdapter {
            public ?LlmRequest $lastRequest = null;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->lastRequest = $request;

                return new LlmResponse(
                    content: '{"select":[{"table":"r","column":"id"}],"from":{"table":"records","alias":"r"},"limit":10}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $generator = new QueryPlanGenerator($llm);
        $plan = $generator->generatePlan(
            'Show me record identifiers',
            ['tables' => [['name' => 'records', 'columns' => [['name' => 'id']]]]],
        );

        self::assertSame('records', $plan['from']['table']);
        self::assertSame(10, $plan['limit']);
        self::assertNotNull($llm->lastRequest);
        self::assertStringContainsString(
            'You do not write SQL',
            $llm->lastRequest->systemPrompt
        );
        self::assertStringContainsString(
            'Show me record identifiers',
            $llm->lastRequest->userMessage
        );
    }

    public function testItRejectsMalformedJson(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: 'Here is your query plan: {not valid JSON}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new QueryPlanGenerator($llm))->generatePlan(
            'Show records',
            ['tables' => []],
        );
    }

    public function testItRejectsAPlanWhenSchemaContextIsInsufficient(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: '{"error":"insufficient_schema"}',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insufficient');

        (new QueryPlanGenerator($llm))->generatePlan(
            'Find a record',
            ['tables' => []],
        );
    }

    public function testItRejectsOversizedResponses(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                return new LlmResponse(
                    content: str_repeat('x', 20001),
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeded the allowed size');

        (new QueryPlanGenerator($llm))->generatePlan(
            'Find a record',
            ['tables' => []],
        );
    }
}
