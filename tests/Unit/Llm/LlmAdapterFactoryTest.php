<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Llm;

use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Llm\Adapters\FakeLlmAdapter;
use CzAiAgent\Llm\LlmAdapterFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LlmAdapterFactoryTest extends TestCase
{
    public function testItCreatesTheConfiguredFakeAdapter(): void
    {
        $factory = new LlmAdapterFactory(
            new LlmConfig('fake')
        );

        $adapter = $factory->create();

        $this->assertInstanceOf(
            FakeLlmAdapter::class,
            $adapter
        );
    }

    public function testItRejectsUnsupportedProviders(): void
    {
        $factory = new LlmAdapterFactory(
            new LlmConfig('unknown')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported LLM provider: unknown'
        );

        $factory->create();
    }
}
