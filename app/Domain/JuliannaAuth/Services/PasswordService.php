<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use InvalidArgumentException;

class PasswordService
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    public function hash(string $password): string
    {
        $this->validate($password);

        return $this->hashUnchecked($password);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public function dummyHash(): string
    {
        static $hash;

        return $hash ??= $this->hashUnchecked(bin2hex(random_bytes(32)));
    }

    public function validate(string $password): void
    {
        $length = mb_strlen($password, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Password must contain between %d and %d characters.',
                self::MIN_LENGTH,
                self::MAX_LENGTH
            ));
        }
    }

    private function hashUnchecked(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
