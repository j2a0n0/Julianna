<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class AssistantTurn
{
    /** @param list<ToolCall> $toolCalls */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $finishReason,
        public readonly ?string $reasoningContent = null,
    ) {
        if (! in_array($finishReason, ['completed', 'tool_calls'], true)
            || mb_strlen($text) > 20000
            || ($finishReason === 'completed' && (trim($text) === '' || $toolCalls !== []))
            || ($finishReason === 'tool_calls' && $toolCalls === [])
            || ($reasoningContent !== null && strlen($reasoningContent) > 100000)) {
            throw new InvalidArgumentException('Invalid assistant turn.');
        }

        foreach ($toolCalls as $call) {
            if (! $call instanceof ToolCall) {
                throw new InvalidArgumentException('Invalid assistant turn.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function asMessage(): array
    {
        $message = [
            'role' => 'assistant',
            'content' => $this->text,
            'tool_calls' => array_map(static fn (ToolCall $call): array => $call->toArray(), $this->toolCalls),
        ];
        if ($this->reasoningContent !== null) {
            $message['reasoning_content'] = $this->reasoningContent;
        }

        return $message;
    }
}
