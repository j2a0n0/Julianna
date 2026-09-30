<?php

declare(strict_types=1);

namespace Leantime\Domain\ProjectAgent\Jobs;

use Leantime\Domain\ProjectAgent\Services\ProjectAgent;

/** Existing default queue workers call handle() with the serialized run ID. */
final class ReviewProjectJob
{
    public function __construct(private readonly ProjectAgent $agent) {}

    public function handle(mixed $payload): bool
    {
        return is_int($payload) && $payload > 0 && $this->agent->processRun($payload);
    }
}
