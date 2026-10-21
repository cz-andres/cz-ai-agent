<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Config;

use CzAiAgent\Config\LlmConfig;
use PHPUnit\Framework\TestCase;

final class LlmConfigTest extends TestCase
{
    private string|false $originalProvider;

    protected function setUp(): void
    {
        $this->originalProvider = getenv('LLM_PROVIDER');
    }

    protected function tearDown(): void
    {
        if ($this->originalProvider === false) {
            putenv('LLM_PROVIDER');
        } else {
            putenv('LLM_PROVIDER=' . $this->originalProvider);
        }
    }

    public function testItDefaultsToFakeWhenProviderIsMissing(): void
    {
        putenv('LLM_PROVIDER');

        $config = LlmConfig::fromEnvironment();

        $this->assertSame('fake', $config->provider);
    }

    public function testItReadsAndNormalisesTheProvider(): void
    {
        putenv('LLM_PROVIDER=  FAKE  ');

        $config = LlmConfig::fromEnvironment();

        $this->assertSame('fake', $config->provider);
    }
}
