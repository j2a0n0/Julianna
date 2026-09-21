<?php

namespace Leantime\Domain\JuliannaAuth\Models;

final readonly class TotpEnrollment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
    ) {}
}
