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
        private readonly string $endpoint = 'https://api.openai.com/v1/chat/completions',
        private readonly bool $jsonMode = true,
        private readonly bool $disableThinking = false,
        private readonly bool $preserveReasoning = false,
    ) {}

    public function respond(ChatRequest $request): ChatResponse
    {
        try {
            $payload = [
                'model' => $this->model,
                'messages' => IdeaRoomPrompt::messages($request),
            ];
            if ($this->jsonMode) {
                $payload['response_format'] = ['type' => 'json_object'];
            }
            if ($this->disableThinking) {
                $payload['thinking'] = ['type' => 'disabled'];
            }

            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'connect_timeout' => 5,
                'timeout' => 60,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw self::httpFailure($response->getStatusCode());
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

    public function turn(ChatRequest $request): AssistantTurn
    {
        try {
            $payload = [
                'model' => $this->model,
                'messages' => $this->toolMessages($request),
            ];
            if ($request->tools !== []) {
                $payload['tools'] = array_map(
                    static fn (ToolDefinition $tool): array => $tool->openAiSchema(),
                    $request->tools,
                );
                $payload['tool_choice'] = 'auto';
                if ($this->needsNoReasoningForTools()) {
                    $payload['reasoning_effort'] = 'none';
                }
            }
            if ($this->disableThinking) {
                $payload['thinking'] = ['type' => 'disabled'];
            }

            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'connect_timeout' => 5,
                'timeout' => 60,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw self::httpFailure($response->getStatusCode());
            }

            $rawBody = (string) $response->getBody();
            if (strlen($rawBody) > 1000000) {
                throw new ProviderException('AI provider returned an invalid response.');
            }

            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            return ToolTurnParser::openAi($body, $request->tools);
        } catch (GuzzleException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        } catch (JsonException|\InvalidArgumentException) {
            throw new ProviderException('AI provider returned an invalid response.');
        }
    }

    public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn
    {
        try {
            $payload = [
                'model' => $this->model,
                'messages' => $this->toolMessages($request),
                'stream' => true,
            ];
            if ($request->tools !== []) {
                $payload['tools'] = array_map(static fn (ToolDefinition $tool): array => $tool->openAiSchema(), $request->tools);
                $payload['tool_choice'] = 'auto';
                if ($this->needsNoReasoningForTools()) {
                    $payload['reasoning_effort'] = 'none';
                }
            }
            if ($this->disableThinking) {
                $payload['thinking'] = ['type' => 'disabled'];
            }
            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
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
                throw self::httpFailure($response->getStatusCode());
            }

            $text = '';
            $reasoning = '';
            $calls = [];
            $knownToolNames = [];
            foreach ($request->tools as $tool) {
                $knownToolNames[$tool->name] = true;
            }
            $reason = null;
            $done = false;
            SseFrames::consume($response->getBody(), function (string $event, string $data) use (&$text, &$reasoning, &$calls, &$reason, &$done, $knownToolNames, $onDelta): void {
                if ($data === '[DONE]') {
                    $done = true;

                    return;
                }
                if ($done || $event === 'error') {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                $chunk = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($chunk)) {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                $choice = $chunk['choices'][0] ?? null;
                if ($choice === null) {
                    return; // Usage-only final chunk.
                }
                if (! is_array($choice) || ! is_array($choice['delta'] ?? null)) {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                $delta = $choice['delta'];
                if ($this->preserveReasoning && isset($delta['reasoning_content'])) {
                    if (! is_string($delta['reasoning_content']) || strlen($reasoning) + strlen($delta['reasoning_content']) > 100000) {
                        throw new ProviderException('AI provider returned an invalid response.');
                    }
                    $reasoning .= $delta['reasoning_content'];
                }
                if (isset($delta['content'])) {
                    if (! is_string($delta['content'])) {
                        throw new ProviderException('AI provider returned an invalid response.');
                    }
                    $text .= $delta['content'];
                    if ($delta['content'] !== '') {
                        $onDelta($delta['content']);
                    }
                }
                $toolParts = $delta['tool_calls'] ?? [];
                if (! is_array($toolParts)) {
                    throw new ProviderException('AI provider returned an invalid tool call (invalid stream shape).');
                }
                foreach ($toolParts as $part) {
                    if (! is_array($part) || ! is_int($part['index'] ?? null) || $part['index'] < 0 || $part['index'] >= 20) {
                        throw new ProviderException('AI provider returned an invalid tool call (invalid stream index).');
                    }
                    $index = $part['index'];
                    $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    if (isset($part['type']) && $part['type'] !== 'function') {
                        throw new ProviderException('AI provider returned an invalid tool call.');
                    }
                    if (isset($part['id'])) {
                        if (! is_string($part['id'])) {
                            throw new ProviderException('AI provider returned an invalid tool call.');
                        }
                        // Some compatible APIs repeat an empty ID in argument-only chunks.
                        // Keep the first non-empty identity, but never merge distinct calls.
                        if ($part['id'] !== '') {
                            if ($calls[$index]['id'] !== '' && $calls[$index]['id'] !== $part['id']) {
                                throw new ProviderException('AI provider returned an invalid tool call (conflicting identity).');
                            }
                            $calls[$index]['id'] = $part['id'];
                        }
                    }
                    if (isset($part['function']) && ! is_array($part['function'])) {
                        throw new ProviderException('AI provider returned an invalid tool call.');
                    }
                    $function = $part['function'] ?? [];
                    if (isset($function['name'])) {
                        if (! is_string($function['name'])) {
                            throw new ProviderException('AI provider returned an invalid tool call.');
                        }
                        if ($function['name'] !== '') {
                            $currentName = $calls[$index]['function']['name'];
                            if (isset($knownToolNames[$function['name']])) {
                                // A complete name may be repeated in later deltas.
                                if ($currentName !== '' && $currentName !== $function['name']
                                    && (isset($knownToolNames[$currentName]) || ! str_starts_with($function['name'], $currentName))) {
                                    throw new ProviderException('AI provider returned an invalid tool call (conflicting identity).');
                                }
                                $calls[$index]['function']['name'] = $function['name'];
                            } elseif (! isset($knownToolNames[$currentName])) {
                                // Also accept providers that split the name across chunks.
                                $calls[$index]['function']['name'] .= $function['name'];
                            } else {
                                throw new ProviderException('AI provider returned an invalid tool call (conflicting identity).');
                            }
                        }
                    }
                    if (isset($function['arguments'])) {
                        if (! is_string($function['arguments'])) {
                            throw new ProviderException('AI provider returned an invalid tool call.');
                        }
                        $calls[$index]['function']['arguments'] .= $function['arguments'];
                        if (strlen($calls[$index]['function']['arguments']) > 100000) {
                            throw new ProviderException('AI provider returned an invalid tool call.');
                        }
                    }
                }
                if (($choice['finish_reason'] ?? null) !== null) {
                    $reason = $choice['finish_reason'];
                }
            });
            if (! $done || $reason === null) {
                throw new ProviderException('AI provider returned an incomplete response.');
            }
            ksort($calls);
            if ($reason === 'tool_calls') {
                if ($calls === []) {
                    throw new ProviderException('AI provider returned an invalid tool call (missing identity).');
                }
                $seenCallIds = [];
                foreach ($calls as $call) {
                    if ($call['id'] === '' || $call['function']['name'] === '') {
                        throw new ProviderException('AI provider returned an invalid tool call (missing identity).');
                    }
                    if (isset($seenCallIds[$call['id']])) {
                        throw new ProviderException('AI provider returned an invalid tool call (duplicate identity).');
                    }
                    $seenCallIds[$call['id']] = true;
                    if (! isset($knownToolNames[$call['function']['name']])) {
                        throw new ProviderException('AI provider returned an invalid tool call (unknown tool name).');
                    }
                    if (! (json_decode($call['function']['arguments'], false, 64) instanceof \stdClass)) {
                        throw new ProviderException('AI provider returned an invalid tool call (malformed arguments).');
                    }
                }
            }

            return ToolTurnParser::openAi([
                'choices' => [['finish_reason' => $reason, 'message' => [
                    'content' => $text, 'reasoning_content' => $reasoning === '' ? null : $reasoning,
                    'tool_calls' => array_values($calls),
                ]]],
            ], $request->tools);
        } catch (GuzzleException) {
            throw new ProviderException('AI provider is unavailable. Please try again.');
        } catch (JsonException|\InvalidArgumentException) {
            throw new ProviderException('AI provider returned an invalid response.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function toolMessages(ChatRequest $request): array
    {
        $messages = IdeaRoomPrompt::toolMessages($request);
        foreach ($messages as &$message) {
            if (! $this->preserveReasoning) {
                unset($message['reasoning_content']);
            }
            if (($message['role'] ?? null) === 'assistant' && ($message['tool_calls'] ?? []) !== []) {
                $message['tool_calls'] = array_map(static function (ToolCall|array $call): array {
                    $call = is_array($call) ? ToolCall::fromArray($call) : $call;

                    return [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => json_encode($call->arguments === [] ? (object) [] : $call->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ];
                }, $message['tool_calls']);
            }
            if (($message['role'] ?? null) === 'tool') {
                unset($message['name'], $message['is_error']);
            }
        }

        return $messages;
    }

    private static function httpFailure(int $status): ProviderException
    {
        return new ProviderException(match ($status) {
            400 => 'AI provider rejected the request (HTTP 400). Check the model and tool configuration.',
            401, 403 => 'AI provider rejected the API key or access (HTTP '.$status.').',
            402 => 'AI provider requires billing setup (HTTP 402).',
            404 => 'AI provider could not use the selected model (HTTP 404).',
            429 => 'AI provider rate limit reached (HTTP 429). Please retry later.',
            default => 'AI provider is unavailable (HTTP '.$status.'). Please try again.',
        }, $status);
    }

    private function needsNoReasoningForTools(): bool
    {
        // OpenAI accepts gpt-6-luna on Chat Completions, but rejects function
        // tools at its default reasoning effort. Other provider adapters and
        // model IDs must keep their own request parameters.
        return $this->endpoint === 'https://api.openai.com/v1/chat/completions'
            && $this->model === 'gpt-6-luna';
    }
}
