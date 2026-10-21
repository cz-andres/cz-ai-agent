<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Agent;

use CzAiAgent\Agent\InsuranceAnalysisAgent;
use CzAiAgent\Llm\Adapters\FakeLlmAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InsuranceAnalysisAgentTest extends TestCase
{
    public function testItReturnsAnAnswerFromTheAdapter(): void
    {
        $agent = new InsuranceAnalysisAgent(
            new FakeLlmAdapter()
        );

        $response = $agent->answerQuestion(
            'What patterns exist across these policies?'
        );

        $this->assertSame(
            'Fake response to: What patterns exist across these policies?',
            $response->content
        );

        $this->assertSame('fake', $response->provider);
        $this->assertSame('test-model', $response->model);
    }

    public function testItRejectsAnEmptyQuestion(): void
    {
        $agent = new InsuranceAnalysisAgent(
            new FakeLlmAdapter()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The question cannot be empty.'
        );

        $agent->answerQuestion('   ');
    }
}
