<?php
declare(strict_types=1);

namespace CzAiAgent\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
    ) {
    }
}
