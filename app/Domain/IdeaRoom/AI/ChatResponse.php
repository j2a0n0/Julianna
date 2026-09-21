<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ChatResponse
{
    public readonly string $text;

    /** @var array<string, mixed> */
    public readonly array $planPatch;

    /** @param array<string, mixed> $planPatch */
    public function __construct(
        string $text,
        array $planPatch,
    ) {
        if (trim($text) === '' || mb_strlen($text) > 20000) {
            throw new InvalidArgumentException('The AI reply is empty.');
        }

        $this->text = trim($text);
        $this->planPatch = PlanPatch::validate($planPatch);
    }
}
