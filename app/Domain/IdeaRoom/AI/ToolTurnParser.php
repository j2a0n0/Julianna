<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use JsonException;

final class ToolTurnParser
{
    /**
     * @param  array<string, mixed>  $body
     * @param  list<ToolDefinition>  $definitions
     */
    public static function openAi(array $body, array $definitions): AssistantTurn
    {
        $choice = $body['choices'][0] ?? null;
        if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
            throw new ProviderException('AI provider returned an invalid response.');
        }

        $reason = $choice['finish_reason'] ?? null;
        $message = $choice['message'];
        $text = $message['content'] ?? '';
        if ($text === null) {
            $text = '';
        }
        if (! is_string($text)) {
            throw new ProviderException('AI provider returned an invalid response.');
        }
        $reasoning = $message['reasoning_content'] ?? null;
        if ($reasoning !== null && (! is_string($reasoning) || strlen($reasoning) > 100000)) {
            throw new ProviderException('AI provider returned an invalid response.');
        }

        if ($reason === 'stop') {
            if (($message['tool_calls'] ?? []) !== []) {
                throw new ProviderException('AI provider returned an invalid response.');
            }

            return new AssistantTurn(trim($text), [], 'completed', $reasoning);
        }

        if ($reason !== 'tool_calls' || ! is_array($message['tool_calls'] ?? null)) {
            throw new ProviderException('AI provider returned an incomplete response.');
        }

        $known = self::knownNames($definitions);
        $calls = [];
        $ids = [];
        foreach ($message['tool_calls'] as $raw) {
            if (count($calls) >= 20 || ! is_array($raw)
                || ($raw['type'] ?? null) !== 'function'
                || ! is_string($raw['id'] ?? null)
                || ! is_string($raw['function']['name'] ?? null)
                || ! is_string($raw['function']['arguments'] ?? null)) {
                throw new ProviderException('AI provider returned an invalid tool call.');
            }

            $name = $raw['function']['name'];
            if (! isset($known[$name]) || isset($ids[$raw['id']])) {
                throw new ProviderException('AI provider returned an invalid tool call.');
            }
            $ids[$raw['id']] = true;
            $arguments = self::arguments($raw['function']['arguments']);
            $calls[] = new ToolCall($raw['id'], $name, $arguments);
        }

        return new AssistantTurn(trim($text), $calls, 'tool_calls', $reasoning);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<ToolDefinition>  $definitions
     */
    public static function anthropic(array $body, array $definitions): AssistantTurn
    {
        $reason = $body['stop_reason'] ?? null;
        if (! in_array($reason, ['end_turn', 'tool_use'], true)
            || ! is_array($body['content'] ?? null)) {
            throw new ProviderException('AI provider returned an incomplete response.');
        }

        $known = self::knownNames($definitions);
        $text = '';
        $calls = [];
        $ids = [];
        foreach ($body['content'] as $block) {
            if (! is_array($block)) {
                throw new ProviderException('AI provider returned an invalid response.');
            }
            if (($block['type'] ?? null) === 'text') {
                if (! is_string($block['text'] ?? null)) {
                    throw new ProviderException('AI provider returned an invalid response.');
                }
                $text .= $block['text'];

                continue;
            }
            if (($block['type'] ?? null) !== 'tool_use'
                || count($calls) >= 20
                || ! is_string($block['id'] ?? null)
                || ! is_string($block['name'] ?? null)
                || ! is_array($block['input'] ?? null)
                || (array_is_list($block['input']) && $block['input'] !== [])
                || ! isset($known[$block['name']])
                || isset($ids[$block['id']])) {
                throw new ProviderException('AI provider returned an invalid tool call.');
            }
            $ids[$block['id']] = true;
            $calls[] = new ToolCall($block['id'], $block['name'], $block['input']);
        }

        if ($reason === 'tool_use') {
            return new AssistantTurn(trim($text), $calls, 'tool_calls');
        }
        if ($calls !== []) {
            throw new ProviderException('AI provider returned an invalid response.');
        }

        return new AssistantTurn(trim($text), [], 'completed');
    }

    /** @param list<ToolDefinition> $definitions
     * @return array<string, true>
     */
    private static function knownNames(array $definitions): array
    {
        $known = [];
        foreach ($definitions as $definition) {
            $known[$definition->name] = true;
        }

        return $known;
    }

    /** @return array<string, mixed> */
    private static function arguments(string $json): array
    {
        if (strlen($json) > 100000) {
            throw new ProviderException('AI provider returned an invalid tool call.');
        }
        try {
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException('AI provider returned an invalid tool call.');
        }
        if (! $decoded instanceof \stdClass) {
            throw new ProviderException('AI provider returned an invalid tool call.');
        }

        /** @var array<string, mixed> $arguments */
        $arguments = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return $arguments;
    }
}
