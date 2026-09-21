<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use JsonException;

final class ResponseParser
{
    public static function parse(string $content): ChatResponse
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)
                || ! is_string($decoded['text'] ?? null)
                || ! is_array($decoded['plan_patch'] ?? null)
                || array_is_list($decoded['plan_patch']) && $decoded['plan_patch'] !== []) {
                throw new ProviderException('AI provider returned an invalid response.');
            }

            return new ChatResponse($decoded['text'], $decoded['plan_patch']);
        } catch (JsonException|\InvalidArgumentException $e) {
            throw new ProviderException('AI provider returned an invalid response.', previous: $e);
        }
    }
}
