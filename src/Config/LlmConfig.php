<?php
declare(strict_types=1);

namespace CzAiAgent\Config;

final class LlmConfig
{
    public function __construct(
        public readonly string $provider,
        public readonly string $ollamaBaseUrl = 'http://host.docker.internal:11434',
        public readonly string $ollamaModel = 'qwen2.5:7b',
    ) {
    }

    public static function fromEnvironment(): self
    {
        $provider = getenv('LLM_PROVIDER');
        $baseUrl = getenv('OLLAMA_BASE_URL');
        $model = getenv('OLLAMA_MODEL');

        return new self(
            provider: strtolower(trim(
                $provider === false || trim($provider) === ''
                    ? 'fake'
                    : $provider
            )),
            ollamaBaseUrl: trim(
                $baseUrl === false || trim($baseUrl) === ''
                    ? 'http://host.docker.internal:11434'
                    : $baseUrl
            ),
            ollamaModel: trim(
                $model === false || trim($model) === ''
                    ? 'qwen2.5:7b'
                    : $model
            ),
        );
    }
}
