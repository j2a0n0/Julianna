<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ChatRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $currentPlan
     * @param  list<ToolDefinition>  $tools
     * @param  array<string, mixed>  $currentGraph
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $currentPlan = [],
        public readonly ?string $projectContext = null,
        public readonly string $locale = 'fr-CH',
        public readonly array $tools = [],
        public readonly array $currentGraph = [],
        public readonly array $citations = [],
        public readonly string $mode = 'explore',
        public readonly ?string $systemPrompt = null,
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('The conversation must contain at least one message.');
        }

        foreach ($messages as $message) {
            if (! is_array($message)
                || ! in_array($message['role'] ?? null, ['user', 'assistant', 'tool'], true)
                || ! is_string($message['content'] ?? null)
                || strlen($message['content']) > 100000) {
                throw new InvalidArgumentException('The conversation contains an invalid message.');
            }

            if ($message['role'] === 'user' && trim($message['content']) === '') {
                throw new InvalidArgumentException('The conversation contains an invalid message.');
            }

            if ($message['role'] === 'assistant') {
                if (isset($message['reasoning_content'])
                    && (! is_string($message['reasoning_content']) || strlen($message['reasoning_content']) > 100000)) {
                    throw new InvalidArgumentException('The conversation contains invalid reasoning content.');
                }
                $calls = $message['tool_calls'] ?? [];
                if (! is_array($calls) || (trim($message['content']) === '' && $calls === [])) {
                    throw new InvalidArgumentException('The conversation contains an invalid message.');
                }
                foreach ($calls as $call) {
                    if (! $call instanceof ToolCall && ! is_array($call)) {
                        throw new InvalidArgumentException('The conversation contains an invalid tool call.');
                    }
                    if (is_array($call)) {
                        ToolCall::fromArray($call);
                    }
                }
            }

            if ($message['role'] === 'tool') {
                if (! is_string($message['tool_call_id'] ?? null)
                    || ($message['tool_call_id'] ?? '') === ''
                    || ! is_string($message['name'] ?? null)
                    || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $message['name'])) {
                    throw new InvalidArgumentException('The conversation contains an invalid tool result.');
                }
            }
        }

        if (! in_array($messages[array_key_last($messages)]['role'], ['user', 'tool'], true)) {
            throw new InvalidArgumentException('The conversation must end with a user message or tool result.');
        }

        $names = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof ToolDefinition || isset($names[$tool->name])) {
                throw new InvalidArgumentException('Invalid tool definitions.');
            }
            $names[$tool->name] = true;
        }

        if (! in_array($mode, ['explore', 'execute'], true)
            || count($citations) > 20
            || count($currentGraph['nodes'] ?? []) > 250
            || count($currentGraph['links'] ?? []) > 500
            || ($systemPrompt !== null && (trim($systemPrompt) === '' || strlen($systemPrompt) > 20000))) {
            throw new InvalidArgumentException('Invalid room context.');
        }
    }
}
