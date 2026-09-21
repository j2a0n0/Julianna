<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Services;

use InvalidArgumentException;
use Leantime\Domain\JuliannaAuth\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    /** @dataProvider validPasswords */
    public function test_hashes_valid_passwords_with_argon2id(string $password): void
    {
        $service = new PasswordService;
        $hash = $service->hash($password);

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($service->verify($password, $hash));
        $this->assertFalse($service->verify($password.'x', $hash));
        $this->assertFalse($service->needsRehash($hash));
    }

    /** @return array<string, array{string}> */
    public static function validPasswords(): array
    {
        return [
            'minimum length without composition rules' => [str_repeat('a', 12)],
            'spaces and symbols are allowed' => ['a long pass phrase !'],
            'maximum length' => [str_repeat('z', 128)],
        ];
    }

    /** @dataProvider invalidPasswords */
    public function test_rejects_passwords_outside_length_policy(string $password): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PasswordService)->hash($password);
    }

    /** @return array<string, array{string}> */
    public static function invalidPasswords(): array
    {
        return [
            'too short' => [str_repeat('a', 11)],
            'too long' => [str_repeat('a', 129)],
        ];
    }
}
