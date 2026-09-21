<?php

namespace Leantime\Domain\JuliannaAuth\Models;

final readonly class ApprovalResult
{
    /**
     * @param  array<string, mixed>  $user
     */
    public function __construct(
        public Account $account,
        public array $user,
    ) {}
}
