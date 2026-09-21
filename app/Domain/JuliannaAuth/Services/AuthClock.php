<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use Carbon\CarbonImmutable;

class AuthClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function databaseNow(): string
    {
        return $this->format($this->now());
    }

    public function format(CarbonImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
