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

    public function turn(ChatRequest $request): AssistantTurn
    {
        try {
            $prompt = IdeaRoomPrompt::toolMessages($request);
            $system = $prompt[0]['content']."\n\n".$prompt[1]['content'];
            $payload = [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => $this->toolMessages(array_slice($prompt, 2)),
            ];
            if ($request->tools !== []) {
                $payload['tools'] = array_map(
                    static fn (ToolDefinition $tool): array => $tool->anthropicSchema(),
                    $request->tools,
                );
            }

            $response = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
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

            return ToolTurnParser::anthropic($body, $request->tools);
        } catch (GuzzleException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        } catch (JsonException|\InvalidArgumentException) {
            throw new ProviderException('AI provider returned an invalid response.');
        }
    }

    public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn
    {
        try {
            $prompt = IdeaRoomPrompt::toolMessages($request);
            $payload = [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $prompt[0]['content']."\n\n".$prompt[1]['content'],
                'messages' => $this->toolMessages(array_slice($prompt, 2)),
                'stream' => true,
            ];
            if ($request->tools !== []) {
                $payload['tools'] = array_map(static fn (ToolDefinition $tool): array => $tool->anthropicSchema(), $request->tools);
            }
            $response = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'stream' => true,
                'connect_timeout' => 5,
                'timeout' => 60,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new ProviderException('AI provider is unavailable. Please try again.');
            }

            $blocks = [];
            $reason = null;
            $done = false;
            SseFrames::consume($response->getBody(), static function (string $event, string $data) use (&$blocks, &$reason, &$done, $onDelta): void {
                if ($done || $event === 'error') {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                $chunk = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($chunk)) {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                if ($event === 'content_block_start') {
                    $index = $chunk['index'] ?? null;
                    $block = $chunk['content_block'] ?? null;
                    if (! is_int($index) || $index < 0 || $index >= 20 || ! is_array($block)) {
                        throw new ProviderException('AI provider returned an invalid response.');
                    }
                    if (($block['type'] ?? null) === 'text') {
                        $initial = (string) ($block['text'] ?? '');
                        $blocks[$index] = ['type' => 'text', 'text' => $initial];
                        if ($initial !== '') {
                            $onDelta($initial);
                        }
                    } elseif (($block['type'] ?? null) === 'tool_use') {
                        $blocks[$index] = [
                            'type' => 'tool_use', 'id' => $block['id'] ?? null, 'name' => $block['name'] ?? null,
                            'input' => $block['input'] ?? [], 'input_json' => '',
                        ];
                    } else {
                        $blocks[$index] = ['type' => 'ignored']; // Hidden thinking is never forwarded.
                    }
                } elseif ($event === 'content_block_delta') {
                    $index = $chunk['index'] ?? null;
                    $delta = $chunk['delta'] ?? null;
                    if (! is_int($index) || ! isset($blocks[$index]) || ! is_array($delta)) {
                        throw new ProviderException('AI provider returned an invalid response.');
                    }
                    if ($blocks[$index]['type'] === 'text' && ($delta['type'] ?? null) === 'text_delta') {
                        if (! is_string($delta['text'] ?? null)) {
                            throw new ProviderException('AI provider returned an invalid response.');
                        }
                        $blocks[$index]['text'] .= $delta['text'];
                        if ($delta['text'] !== '') {
                            $onDelta($delta['text']);
                        }
                    } elseif ($blocks[$index]['type'] === 'tool_use' && ($delta['type'] ?? null) === 'input_json_delta') {
                        if (! is_string($delta['partial_json'] ?? null)) {
                            throw new ProviderException('AI provider returned an invalid response.');
                        }
                        $blocks[$index]['input_json'] .= $delta['partial_json'];
                    }
                } elseif ($event === 'message_delta') {
                    $reason = $chunk['delta']['stop_reason'] ?? null;
                } elseif ($event === 'message_stop') {
                    $done = true;
                }
            });
            if (! $done || $reason === null) {
                throw new ProviderException('AI provider returned an incomplete response.');
            }
            ksort($blocks);
            $content = [];
            foreach ($blocks as $block) {
                if ($block['type'] === 'ignored') {
                    continue;
                }
                if ($block['type'] === 'tool_use') {
                    if ($block['input_json'] !== '') {
                        $decoded = json_decode($block['input_json'], false, 64, JSON_THROW_ON_ERROR);
                        if (! $decoded instanceof \stdClass) {
                            throw new ProviderException('AI provider returned an invalid tool call.');
                        }
                        $block['input'] = json_decode($block['input_json'], true, 64, JSON_THROW_ON_ERROR);
                    }
                    unset($block['input_json']);
                }
                $content[] = $block;
            }

            return ToolTurnParser::anthropic(['stop_reason' => $reason, 'content' => $content], $request->tools);
        } catch (GuzzleException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        } catch (JsonException|\InvalidArgumentException) {
            throw new ProviderException('AI provider returned an invalid response.');
        }
    }

    /** @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function toolMessages(array $messages): array
    {
        $mapped = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                $mapped[] = ['role' => 'user', 'content' => $message['content']];

                continue;
            }
            if ($message['role'] === 'assistant') {
                $calls = $message['tool_calls'] ?? [];
                if ($calls === []) {
                    $mapped[] = ['role' => 'assistant', 'content' => $message['content']];

                    continue;
                }
                $blocks = [];
                if ($message['content'] !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $message['content']];
                }
                foreach ($calls as $call) {
                    $call = is_array($call) ? ToolCall::fromArray($call) : $call;
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $call->id,
                        'name' => $call->name,
                        'input' => $call->arguments === [] ? (object) [] : $call->arguments,
                    ];
                }
                $mapped[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $block = [
                'type' => 'tool_result',
                'tool_use_id' => $message['tool_call_id'],
                'content' => $message['content'],
            ];
            if ($message['is_error'] ?? false) {
                $block['is_error'] = true;
            }
            $last = array_key_last($mapped);
            if ($last !== null && $mapped[$last]['role'] === 'user' && is_array($mapped[$last]['content'])) {
                $mapped[$last]['content'][] = $block;
            } else {
                $mapped[] = ['role' => 'user', 'content' => [$block]];
            }
        }

        return $mapped;
    }
}
