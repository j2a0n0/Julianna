<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Encryption\Encrypter;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\MfaRepository;
use Leantime\Domain\JuliannaAuth\Services\AuditLog;
use Leantime\Domain\JuliannaAuth\Services\AuthClock;
use Leantime\Domain\JuliannaAuth\Services\MfaService;
use Leantime\Domain\JuliannaAuth\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class MfaServiceTest extends TestCase
{
    public function test_enrollment_stores_only_an_application_key_encrypted_secret(): void
    {
        $account = $this->account(mfaConfirmedAt: null);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('requireById')->with(7)->willReturn($account);

        $encrypted = null;
        $mfa = $this->createMock(MfaRepository::class);
        $mfa->expects($this->once())
            ->method('replaceSecret')
            ->willReturnCallback(function (int $accountId, string $value) use (&$encrypted): void {
                $this->assertSame(7, $accountId);
                $encrypted = $value;
            });

        $clock = $this->createMock(AuthClock::class);
        $clock->method('databaseNow')->willReturn('2026-09-17 12:00:00');
        $audit = $this->createMock(AuditLog::class);
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $service = new MfaService(
            $this->createMock(ConnectionInterface::class),
            $accounts,
            $mfa,
            $encrypter,
            new PasswordService,
            $audit,
            $clock,
        );

        $enrollment = $service->begin(7);

        $this->assertNotNull($encrypted);
        $this->assertNotSame($enrollment->secret, $encrypted);
        $this->assertSame($enrollment->secret, $encrypter->decrypt($encrypted, false));
        $this->assertStringContainsString('otpauth://totp/', $enrollment->provisioningUri);
    }

    public function test_recovery_code_cannot_be_reused(): void
    {
        $rawCode = 'ABCD-EF01-2345-6789';
        $passwords = new PasswordService;
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects($this->exactly(2))->method('requireById')->with(7)->willReturn($this->account('2026-09-17 12:00:00'));

        $mfa = $this->createMock(MfaRepository::class);
        $mfa->expects($this->exactly(2))
            ->method('lockUnusedRecoveryCodes')
            ->with(7)
            ->willReturnOnConsecutiveCalls(
                [['id' => 11, 'code_hash' => $passwords->hash('ABCDEF0123456789')]],
                [],
            );
        $mfa->expects($this->once())
            ->method('consumeRecoveryCode')
            ->with(11, '2026-09-17 12:00:00')
            ->willReturn(true);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(2))
            ->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $clock = $this->createMock(AuthClock::class);
        $clock->method('databaseNow')->willReturn('2026-09-17 12:00:00');
        $audit = $this->createMock(AuditLog::class);
        $audit->expects($this->exactly(2))->method('record');
        $service = new MfaService(
            $connection,
            $accounts,
            $mfa,
            new Encrypter(str_repeat('k', 32), 'AES-256-CBC'),
            $passwords,
            $audit,
            $clock,
        );

        $this->assertTrue($service->verifyRecoveryCode(7, $rawCode));
        $this->assertFalse($service->verifyRecoveryCode(7, $rawCode));
    }

    private function account(?string $mfaConfirmedAt): Account
    {
        return new Account(
            id: 7,
            email: 'person@example.com',
            displayName: 'Person Example',
            state: AccountState::ACTIVE,
            userId: 9,
            emailVerifiedAt: '2026-09-17 10:00:00',
            approvedAt: '2026-09-17 11:00:00',
            mfaConfirmedAt: $mfaConfirmedAt,
            sessionVersion: 3,
            createdAt: '2026-09-17 09:00:00',
            updatedAt: '2026-09-17 11:00:00',
        );
    }
}
