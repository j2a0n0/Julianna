<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Services;

use InvalidArgumentException;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Services\EmailNormalizer;
use Leantime\Domain\JuliannaAuth\Services\RateLimitKeyFactory;
use Leantime\Domain\JuliannaAuth\Services\SecureTokenService;
use PHPUnit\Framework\TestCase;

final class IdentityPrimitivesTest extends TestCase
{
    public function test_email_normalization_is_case_insensitive_and_trimmed(): void
    {
        $normalizer = new EmailNormalizer;

        $this->assertSame('person@example.com', $normalizer->normalize('  Person@Example.COM  '));
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EmailNormalizer)->normalize('not an email');
    }

    public function test_tokens_are_random_url_safe_and_only_hashes_need_storage(): void
    {
        $tokens = new SecureTokenService;
        $first = $tokens->generate();
        $second = $tokens->generate();

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        $this->assertSame(hash('sha256', $first), $tokens->hash($first));
        $this->assertNotSame($first, $tokens->hash($first));
    }

    public function test_rate_limit_keys_hide_pii_and_scope_login_identity_to_ip(): void
    {
        $keys = new RateLimitKeyFactory;
        $identity = $keys->loginIdentity('203.0.113.4', 'Person@Example.com');

        $this->assertSame($identity, $keys->loginIdentity('203.0.113.4', ' person@example.COM '));
        $this->assertNotSame($identity, $keys->loginIdentity('203.0.113.5', 'person@example.com'));
        $this->assertStringNotContainsString('203.0.113.4', $identity);
        $this->assertStringNotContainsString('person@example.com', $identity);
        $this->assertNotSame($keys->loginIp('203.0.113.4'), $keys->registration('203.0.113.4'));
    }

    public function test_only_linked_active_accounts_can_authenticate(): void
    {
        $active = $this->account(AccountState::ACTIVE, userId: 8, mfaConfirmedAt: null);
        $pending = $this->account(AccountState::PENDING_APPROVAL, userId: null, mfaConfirmedAt: null);

        $this->assertTrue($active->canAuthenticate());
        $this->assertTrue($active->requiresMfaEnrollment());
        $this->assertFalse($pending->canAuthenticate());
        $this->assertFalse($pending->requiresMfaEnrollment());
    }

    private function account(AccountState $state, ?int $userId, ?string $mfaConfirmedAt): Account
    {
        return new Account(
            id: 3,
            email: 'person@example.com',
            displayName: 'Person Example',
            state: $state,
            userId: $userId,
            emailVerifiedAt: null,
            approvedAt: null,
            mfaConfirmedAt: $mfaConfirmedAt,
            sessionVersion: 1,
            createdAt: '2026-09-17 12:00:00',
            updatedAt: '2026-09-17 12:00:00',
        );
    }
}
