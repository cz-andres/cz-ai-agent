<?php
declare(strict_types=1);

namespace CzAiAgent\Llm\Adapters;

use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;
use JsonException;
use RuntimeException;

final class OllamaAdapter implements LlmAdapter
{
    public function __construct(
        private readonly string $baseUrl = 'http://host.docker.internal:11434',
        private readonly string $model = 'qwen2.5:7b',
        private readonly int $timeoutSeconds = 120,
    ) {
    }


    public function generate(LlmRequest $request): LlmResponse
    {
        $url = rtrim($this->baseUrl, '/') . '/api/chat';

        $messages = [
            [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ],
        ];

        foreach ($request->history as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $request->userMessage,
        ];

        try {
            $payload = json_encode([
                'model' => $this->model,
                'messages' => $messages,
                'stream' => false,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode the Ollama request.',
                0,
                $exception,
            );
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Could not initialise the HTTP client.'
            );
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);

            $body = curl_exec($curl);

            if ($body === false) {
                throw new RuntimeException(
                    'Ollama request failed: ' . curl_error($curl)
                );
            }

            $statusCode = (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );
        } finally {
            curl_close($curl);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                "Ollama returned HTTP status {$statusCode}."
            );
        }

        try {
            $data = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Ollama returned invalid JSON.',
                0,
                $exception,
            );
        }

        if (
            !is_array($data)
            || !isset($data['message']['content'])
            || !is_string($data['message']['content'])
        ) {
            throw new RuntimeException(
                'Ollama returned an unexpected response format.'
            );
        }

        return new LlmResponse(
            content: $data['message']['content'],
            provider: 'ollama',
            model: is_string($data['model'] ?? null)
                ? $data['model']
                : $this->model,
        );
    }

}
