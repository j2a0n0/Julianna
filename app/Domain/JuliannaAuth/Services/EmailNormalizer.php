<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use InvalidArgumentException;

class EmailNormalizer
{
    public function normalize(string $email): string
    {
        $normalized = mb_strtolower(trim($email), 'UTF-8');

        if (strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        return $normalized;
    }
}
