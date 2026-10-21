<?php
declare(strict_types=1);

namespace CzAiAgent\Llm;

final class LlmRequest
{
    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $history
     */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userMessage,
        public readonly array $history = [],
    ) {
    }
}
