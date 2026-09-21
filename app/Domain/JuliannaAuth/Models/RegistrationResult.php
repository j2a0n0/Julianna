<?php

namespace Leantime\Domain\JuliannaAuth\Models;

/**
 * Controllers must always render the same public response whether `created` is
 * true or false. Only trusted mail-delivery code may use the account and token;
 * they are also present for a rate-limited retry of an unverified signup.
 */
final readonly class RegistrationResult
{
    public function __construct(
        public bool $created,
        public ?Account $account = null,
        public ?string $verificationToken = null,
    ) {}
}
