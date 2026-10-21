<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Llm;

use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Llm\Adapters\OllamaAdapter;
use CzAiAgent\Llm\LlmAdapterFactory;
use PHPUnit\Framework\TestCase;

final class OllamaAdapterFactoryTest extends TestCase
{
    public function testItCreatesTheConfiguredOllamaAdapter(): void
    {
        $factory = new LlmAdapterFactory(
            new LlmConfig(
                provider: 'ollama',
                ollamaBaseUrl: 'http://ollama.test:11434',
                ollamaModel: 'qwen2.5:7b',
            )
        );

        $adapter = $factory->create();

        $this->assertInstanceOf(
            OllamaAdapter::class,
            $adapter
        );
    }
}
