<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

interface AiProvider
{
    public function respond(ChatRequest $request): ChatResponse;
}
