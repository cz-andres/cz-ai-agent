<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Agent;

use CzAiAgent\Agent\EvidenceAnswerGenerator;
use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EvidenceAnswerGeneratorTest extends TestCase
{
    public function testItPassesQueryEvidenceToTheLlm(): void
    {
        $llm = new class implements LlmAdapter {
            public ?LlmRequest $lastRequest = null;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->lastRequest = $request;

                return new LlmResponse(
                    content: 'The query returned one matching record.',
                    provider: 'test',
                    model: 'test-model',
                );
            }
        };

        $response = (new EvidenceAnswerGenerator($llm))->generateAnswer(
            'How many records matched?',
            [
                'row_count' => 1,
                'rows' => [['record_count' => 1]],
                'evidence' => [
                    'source' => 'read_only_database_query',
                    'tables' => ['records'],
                    'returned_rows' => 1,
                ],
            ],
        );

        self::assertSame(
            'The query returned one matching record.',
            $response->content,
        );
        self::assertNotNull($llm->lastRequest);
        self::assertStringContainsString(
            'record_count',
            $llm->lastRequest->userMessage,
        );
        self::assertStringContainsString(
            'Do not invent records',
            $llm->lastRequest->systemPrompt,
        );
    }

    public function testItRejectsEmptyQuestions(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                self::fail('The LLM must not be called.');

                return new LlmResponse('', 'test', 'test-model');
            }
        };

        $this->expectException(\InvalidArgumentException::class);

        (new EvidenceAnswerGenerator($llm))->generateAnswer(
            ' ',
            ['rows' => []],
        );
    }

    public function testItRejectsOversizedEvidence(): void
    {
        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                self::fail('The LLM must not receive oversized evidence.');

                return new LlmResponse('', 'test', 'test-model');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeded the allowed size');

        (new EvidenceAnswerGenerator($llm))->generateAnswer(
            'Summarise these records.',
            ['data' => str_repeat('x', 100001)],
        );
    }
}
