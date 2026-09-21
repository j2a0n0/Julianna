<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Services;

use Carbon\CarbonImmutable;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;
use Leantime\Domain\JuliannaAuth\Services\AuthClock;
use Leantime\Domain\JuliannaAuth\Services\SessionRegistry;
use PHPUnit\Framework\TestCase;

final class SessionRegistryTest extends TestCase
{
    public function test_session_registration_stores_only_hash_and_account_version(): void
    {
        $account = new Account(
            id: 7,
            email: 'person@example.com',
            displayName: 'Person Example',
            state: AccountState::ACTIVE,
            userId: 9,
            emailVerifiedAt: '2026-09-17 10:00:00',
            approvedAt: '2026-09-17 11:00:00',
            mfaConfirmedAt: '2026-09-17 12:00:00',
            sessionVersion: 4,
            createdAt: '2026-09-17 09:00:00',
            updatedAt: '2026-09-17 12:00:00',
        );
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('requireById')->with(7)->willReturn($account);
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->once())->method('register')->with(
            7,
            hash('sha256', 'php-session-secret'),
            4,
            '2026-09-17 12:30:00',
            '2026-09-17 12:00:00',
        );
        $clock = $this->createMock(AuthClock::class);
        $clock->method('now')->willReturn(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
        $clock->method('format')->willReturnCallback(
            static fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s'),
        );

        $version = (new SessionRegistry($accounts, $sessions, $clock))->start(
            7,
            'php-session-secret',
            30,
        );

        $this->assertSame(4, $version);
    }

    public function test_touch_extends_registry_expiry_by_configured_idle_lifetime(): void
    {
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->once())->method('touch')->with(
            hash('sha256', 'php-session-secret'),
            '2026-09-17 12:00:00',
            '2026-09-17 12:30:00',
        );
        $clock = $this->createMock(AuthClock::class);
        $clock->method('now')->willReturn(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
        $clock->method('format')->willReturnCallback(
            static fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s'),
        );

        (new SessionRegistry($this->createMock(AccountRepository::class), $sessions, $clock))
            ->touch('php-session-secret', 30);
    }
}
