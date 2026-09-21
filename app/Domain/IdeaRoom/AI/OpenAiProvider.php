<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

final class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function respond(ChatRequest $request): ChatResponse
    {
        try {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => IdeaRoomPrompt::messages($request),
                    'response_format' => ['type' => 'json_object'],
                ],
                'connect_timeout' => 5,
                'timeout' => 60,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new ProviderException('AI provider is unavailable. Please try again.');
            }

            $rawBody = (string) $response->getBody();
            if (strlen($rawBody) > 1000000) {
                throw new ProviderException('AI provider returned an invalid response.');
            }

            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            $choice = $body['choices'][0] ?? null;
            if (! is_array($choice)
                || ($choice['finish_reason'] ?? null) !== 'stop'
                || ! is_string($choice['message']['content'] ?? null)) {
                throw new ProviderException('AI provider returned an incomplete response.');
            }

            return ResponseParser::parse($choice['message']['content']);
        } catch (GuzzleException|JsonException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        }
    }
}
