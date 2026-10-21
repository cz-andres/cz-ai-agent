<?php
declare(strict_types=1);

namespace CzAiAgent\Llm;

interface LlmAdapter
{
    public function generate(LlmRequest $request): LlmResponse;
}
