<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Models\ApprovalResult;
use Leantime\Domain\JuliannaAuth\Models\RegistrationResult;
use Leantime\Domain\JuliannaAuth\Models\TotpEnrollment;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;

/**
 * Integration façade for Julianna's controllers and middleware.
 *
 * This service never sends email and never mutates the PHP session. Raw tokens
 * and recovery codes are returned only to the trusted caller that must deliver
 * or display them.
 *
 * @api
 */
class JuliannaAuth
{
    public function __construct(
        private AccountService $accountService,
        private ApprovalService $approvalService,
        private MfaService $mfaService,
        private SessionRegistry $sessionRegistry,
        private AccountRepository $accounts,
        private AuditLog $auditLog,
    ) {}

    public function register(string $name, string $email, string $password): RegistrationResult
    {
        return $this->accountService->register($name, $email, $password);
    }

    public function verifyEmail(string $rawToken): Account|false
    {
        return $this->accountService->verifyEmail($rawToken);
    }

    public function authenticate(string $email, string $password): Account|false
    {
        return $this->accountService->authenticate($email, $password);
    }

    public function account(int $accountId): ?Account
    {
        return $this->accounts->findById($accountId);
    }

    public function issuePasswordReset(string $email): ?string
    {
        return $this->accountService->issuePasswordReset($email);
    }

    public function resetPassword(string $rawToken, string $password): bool
    {
        return $this->accountService->resetPassword($rawToken, $password);
    }

    /**
     * @param  array<int, int|string>  $projectIds
     */
    public function approve(int $accountId, string $role, array $projectIds, int $approverUserId): ApprovalResult
    {
        return $this->approvalService->approve($accountId, $role, $projectIds, $approverUserId);
    }

    public function reject(int $accountId, int $approverUserId): Account
    {
        return $this->approvalService->reject($accountId, $approverUserId);
    }

    public function disable(int $accountId, int $actorUserId): Account
    {
        return $this->approvalService->disable($accountId, $actorUserId);
    }

    public function beginTotp(int $accountId): TotpEnrollment
    {
        return $this->mfaService->begin($accountId);
    }

    /**
     * @return array<int, string>|false
     */
    public function confirmTotp(int $accountId, string $code): array|false
    {
        return $this->mfaService->confirm($accountId, $code);
    }

    public function verifyTotp(int $accountId, string $code): bool
    {
        return $this->mfaService->verify($accountId, $code);
    }

    public function verifyRecoveryCode(int $accountId, string $code): bool
    {
        return $this->mfaService->verifyRecoveryCode($accountId, $code);
    }

    public function startSession(int $accountId, string $phpSessionId, int $lifetimeMinutes = 480): int
    {
        return $this->sessionRegistry->start($accountId, $phpSessionId, $lifetimeMinutes);
    }

    public function validateSession(int $accountId, string $phpSessionId, int $sessionVersion): bool
    {
        return $this->sessionRegistry->isValid($accountId, $phpSessionId, $sessionVersion);
    }

    public function touchSession(string $phpSessionId, int $lifetimeMinutes = 480): void
    {
        $this->sessionRegistry->touch($phpSessionId, $lifetimeMinutes);
    }

    public function revokeSession(string $phpSessionId): void
    {
        $this->sessionRegistry->revoke($phpSessionId);
    }

    public function revokeAccountSessions(int $accountId): void
    {
        $this->sessionRegistry->revokeAll($accountId);
    }

    /**
     * @return array<int, Account>
     */
    public function pendingApproval(): array
    {
        return $this->accounts->pendingApproval();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function audit(int $accountId): array
    {
        return $this->auditLog->forAccount($accountId);
    }
}
