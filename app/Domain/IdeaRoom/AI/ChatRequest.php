<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ChatRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $currentPlan
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $currentPlan = [],
        public readonly ?string $projectContext = null,
        public readonly string $locale = 'fr-CH',
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('The conversation must contain at least one message.');
        }

        foreach ($messages as $message) {
            if (! is_array($message)
                || ! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)
                || trim($message['content']) === '') {
                throw new InvalidArgumentException('The conversation contains an invalid message.');
            }
        }

        if ($messages[array_key_last($messages)]['role'] !== 'user') {
            throw new InvalidArgumentException('The conversation must end with a user message.');
        }
    }
}
