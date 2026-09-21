<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

final class AnthropicProvider implements AiProvider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function respond(ChatRequest $request): ChatResponse
    {
        try {
            $prompt = IdeaRoomPrompt::messages($request);
            $system = $prompt[0]['content']."\n\n".$prompt[1]['content'];

            $response = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'system' => $system,
                    'messages' => array_slice($prompt, 2),
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
            if (($body['stop_reason'] ?? null) !== 'end_turn' || ! is_array($body['content'] ?? null)) {
                throw new ProviderException('AI provider returned an incomplete response.');
            }

            $content = '';
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $content .= $block['text'];
                }
            }

            return ResponseParser::parse($content);
        } catch (GuzzleException|JsonException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        }
    }
}
