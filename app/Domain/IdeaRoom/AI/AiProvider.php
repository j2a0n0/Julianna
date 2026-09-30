<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

interface AiProvider
{
    public function respond(ChatRequest $request): ChatResponse;

    public function turn(ChatRequest $request): AssistantTurn;

    /** @param callable(string): void $onDelta Receives visible text only, never hidden reasoning. */
    public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn;
}
