<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Enums\TokenPurpose;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\AuthTokenRepository;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;
use Leantime\Domain\JuliannaAuth\Services\AccountService;
use Leantime\Domain\JuliannaAuth\Services\AuditLog;
use Leantime\Domain\JuliannaAuth\Services\AuthClock;
use Leantime\Domain\JuliannaAuth\Services\EmailNormalizer;
use Leantime\Domain\JuliannaAuth\Services\PasswordService;
use Leantime\Domain\JuliannaAuth\Services\SecureTokenService;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase
{
    /** @dataProvider blockedStates */
    public function test_rejected_disabled_and_unapproved_accounts_cannot_authenticate(AccountState $state): void
    {
        $passwords = new PasswordService;
        $account = $this->account($state, $state === AccountState::UNVERIFIED ? null : 12);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findCredentialsByEmail')->willReturn([
            'account' => $account,
            'password_hash' => $passwords->hash('CorrectPassword123!'),
        ]);
        $audit = $this->createMock(AuditLog::class);
        $audit->expects($this->once())->method('record');

        $result = $this->service($accounts, $audit, $passwords)->authenticate(
            'PERSON@EXAMPLE.COM',
            'CorrectPassword123!',
        );

        $this->assertFalse($result);
    }

    /** @return array<string, array{AccountState}> */
    public static function blockedStates(): array
    {
        return [
            'unverified' => [AccountState::UNVERIFIED],
            'pending approval' => [AccountState::PENDING_APPROVAL],
            'rejected' => [AccountState::REJECTED],
            'disabled' => [AccountState::DISABLED],
        ];
    }

    public function test_duplicate_registration_returns_the_generic_not_created_result(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByEmail')->with('person@example.com')->willReturn(
            $this->account(AccountState::ACTIVE, 12),
        );

        $result = $this->service(
            $accounts,
            $this->createMock(AuditLog::class),
            new PasswordService,
        )->register('Person Example', ' PERSON@example.com ', 'CorrectPassword123!');

        $this->assertFalse($result->created);
        $this->assertNull($result->account);
        $this->assertNull($result->verificationToken);
    }

    public function test_unverified_duplicate_can_retry_delivery_after_cooldown_without_changing_credentials(): void
    {
        $account = $this->account(AccountState::UNVERIFIED, null);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects($this->exactly(2))->method('findByEmail')->with('person@example.com')->willReturn($account);
        $accounts->expects($this->once())->method('lockRow')->with(7)->willReturn(['state' => AccountState::UNVERIFIED->value]);
        $accounts->expects($this->never())->method('update');

        $tokens = $this->createMock(AuthTokenRepository::class);
        $tokens->expects($this->once())->method('latestCreatedAt')
            ->with(7, TokenPurpose::EMAIL_VERIFICATION)->willReturn('2026-09-17 11:54:00');
        $tokens->expects($this->once())->method('create')->with(
            7,
            TokenPurpose::EMAIL_VERIFICATION,
            $this->isType('string'),
            '2026-09-18 12:00:00',
            '2026-09-17 12:00:00',
        );

        $result = $this->registrationService($accounts, $tokens)->register(
            'Person Example', ' PERSON@example.com ', 'CorrectPassword123!',
        );

        $this->assertFalse($result->created);
        $this->assertSame($account, $result->account);
        $this->assertNotEmpty($result->verificationToken);
    }

    public function test_unverified_duplicate_respects_per_account_resend_cooldown(): void
    {
        $account = $this->account(AccountState::UNVERIFIED, null);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByEmail')->willReturn($account);
        $accounts->method('lockRow')->willReturn(['state' => AccountState::UNVERIFIED->value]);
        $tokens = $this->createMock(AuthTokenRepository::class);
        $tokens->method('latestCreatedAt')->willReturn('2026-09-17 11:59:00');
        $tokens->expects($this->never())->method('create');

        $result = $this->registrationService($accounts, $tokens)->register(
            'Person Example', 'person@example.com', 'CorrectPassword123!',
        );

        $this->assertFalse($result->created);
        $this->assertNull($result->account);
        $this->assertNull($result->verificationToken);
    }

    public function test_password_reset_consumes_token_and_invalidates_every_session(): void
    {
        $tokens = $this->createMock(AuthTokenRepository::class);
        $tokens->expects($this->exactly(2))
            ->method('lockUsable')
            ->willReturnOnConsecutiveCalls(['id' => 4, 'account_id' => 7], null);
        $tokens->expects($this->once())->method('consume')->with(4, '2026-09-17 12:00:00')->willReturn(true);
        $tokens->expects($this->once())->method('expireOutstanding');

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects($this->once())->method('lockRow')->with(7)->willReturn([
            'id' => 7,
            'user_id' => 12,
            'state' => AccountState::ACTIVE->value,
            'session_version' => 2,
        ]);
        $updated = null;
        $accounts->expects($this->once())
            ->method('update')
            ->with(7, $this->callback(function (array $values) use (&$updated): bool {
                $updated = $values;

                return true;
            }));

        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->once())->method('revokeAll')->with(7, '2026-09-17 12:00:00');
        $userBuilder = $this->createMock(\Illuminate\Database\Query\Builder::class);
        $userBuilder->method('where')->with('id', 12)->willReturnSelf();
        $userBuilder->expects($this->once())->method('update')->willReturn(1);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(2))
            ->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $connection->expects($this->once())->method('table')->with('zp_user')->willReturn($userBuilder);
        $clock = $this->createMock(AuthClock::class);
        $clock->method('databaseNow')->willReturn('2026-09-17 12:00:00');
        $audit = $this->createMock(AuditLog::class);
        $audit->expects($this->once())->method('record');
        $passwords = new PasswordService;
        $service = new AccountService(
            $connection,
            $accounts,
            $tokens,
            $sessions,
            new EmailNormalizer,
            $passwords,
            new SecureTokenService,
            $audit,
            $clock,
        );

        $this->assertTrue($service->resetPassword('single-use-token', 'NewPassword123!'));
        $this->assertFalse($service->resetPassword('single-use-token', 'NewPassword123!'));
        $this->assertSame(3, $updated['session_version']);
        $this->assertTrue($passwords->verify('NewPassword123!', $updated['password_hash']));
    }

    private function service(AccountRepository $accounts, AuditLog $audit, PasswordService $passwords): AccountService
    {
        return new AccountService(
            $this->createMock(ConnectionInterface::class),
            $accounts,
            $this->createMock(AuthTokenRepository::class),
            $this->createMock(SessionRepository::class),
            new EmailNormalizer,
            $passwords,
            new SecureTokenService,
            $audit,
            $this->createMock(AuthClock::class),
        );
    }

    private function registrationService(AccountRepository $accounts, AuthTokenRepository $tokens): AccountService
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $clock = $this->createMock(AuthClock::class);
        $clock->method('now')->willReturn(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
        $clock->method('format')->willReturnCallback(
            static fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s'),
        );

        return new AccountService(
            $connection,
            $accounts,
            $tokens,
            $this->createMock(SessionRepository::class),
            new EmailNormalizer,
            new PasswordService,
            new SecureTokenService,
            $this->createMock(AuditLog::class),
            $clock,
        );
    }

    private function account(AccountState $state, ?int $userId): Account
    {
        return new Account(
            id: 7,
            email: 'person@example.com',
            displayName: 'Person Example',
            state: $state,
            userId: $userId,
            emailVerifiedAt: null,
            approvedAt: null,
            mfaConfirmedAt: null,
            sessionVersion: 1,
            createdAt: '2026-09-17 09:00:00',
            updatedAt: '2026-09-17 09:00:00',
        );
    }
}
