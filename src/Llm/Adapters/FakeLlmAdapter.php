<?php
declare(strict_types=1);

namespace CzAiAgent\Llm\Adapters;

use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;

final class FakeLlmAdapter implements LlmAdapter
{
    public function generate(LlmRequest $request): LlmResponse
    {
        return new LlmResponse(
            content: 'Fake response to: ' . $request->userMessage,
            provider: 'fake',
            model: 'test-model',
        );
    }
}
