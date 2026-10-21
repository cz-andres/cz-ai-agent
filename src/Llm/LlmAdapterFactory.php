<?php
declare(strict_types=1);

namespace CzAiAgent\Llm;

use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Llm\Adapters\FakeLlmAdapter;
use CzAiAgent\Llm\Adapters\OllamaAdapter;
use InvalidArgumentException;

final class LlmAdapterFactory
{
    public function __construct(
        private readonly LlmConfig $config,
    ) {
    }

    public function create(): LlmAdapter
    {
        return match ($this->config->provider) {
            'fake' => new FakeLlmAdapter(),
            'ollama' => new OllamaAdapter(
                baseUrl: $this->config->ollamaBaseUrl,
                model: $this->config->ollamaModel,
            ),
            default => throw new InvalidArgumentException(
                "Unsupported LLM provider: {$this->config->provider}"
            ),
        };
    }
}
