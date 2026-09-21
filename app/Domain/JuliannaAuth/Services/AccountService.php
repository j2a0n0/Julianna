<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Enums\AuditEvent;
use Leantime\Domain\JuliannaAuth\Enums\TokenPurpose;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Models\RegistrationResult;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\AuthTokenRepository;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;

class AccountService
{
    private const VERIFICATION_RESEND_COOLDOWN_MINUTES = 5;

    public function __construct(
        private ConnectionInterface $connection,
        private AccountRepository $accounts,
        private AuthTokenRepository $tokens,
        private SessionRepository $sessions,
        private EmailNormalizer $emails,
        private PasswordService $passwords,
        private SecureTokenService $secureTokens,
        private AuditLog $audit,
        private AuthClock $clock,
    ) {}

    public function register(string $name, string $email, string $password): RegistrationResult
    {
        $email = $this->emails->normalize($email);
        $name = $this->normalizeName($name);
        $passwordHash = $this->passwords->hash($password);

        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null) {
            return $existing->state === AccountState::UNVERIFIED
                ? $this->retryVerification($email)
                : new RegistrationResult(created: false);
        }

        try {
            return $this->connection->transaction(function () use ($name, $email, $passwordHash): RegistrationResult {
                $now = $this->clock->now();
                $rawToken = $this->secureTokens->generate();
                $account = $this->accounts->create(
                    email: $email,
                    displayName: $name,
                    passwordHash: $passwordHash,
                    now: $this->clock->format($now),
                );

                $this->tokens->create(
                    accountId: $account->id,
                    purpose: TokenPurpose::EMAIL_VERIFICATION,
                    tokenHash: $this->secureTokens->hash($rawToken),
                    expiresAt: $this->clock->format($now->addHours(24)),
                    now: $this->clock->format($now),
                );

                $this->audit->record(
                    AuditEvent::SIGNUP,
                    accountId: $account->id,
                    subjectIdentifier: $email,
                );

                return new RegistrationResult(
                    created: true,
                    account: $account,
                    verificationToken: $rawToken,
                );
            });
        } catch (QueryException $e) {
            // Resolve a concurrent duplicate-email insert to the same generic
            // outcome as the preflight duplicate check. Re-throw unrelated DB errors.
            if ($this->isUniqueConstraintViolation($e)) {
                return new RegistrationResult(created: false);
            }

            throw $e;
        }
    }

    /**
     * An undelivered or expired verification email must not reserve an address
     * forever. Keep the existing password and public response unchanged, and
     * limit resends per account even when callers rotate source IPs.
     */
    private function retryVerification(string $email): RegistrationResult
    {
        return $this->connection->transaction(function () use ($email): RegistrationResult {
            $account = $this->accounts->findByEmail($email);
            if ($account === null) {
                return new RegistrationResult(created: false);
            }

            $row = $this->accounts->lockRow($account->id);
            if ($row === null || $row['state'] !== AccountState::UNVERIFIED->value) {
                return new RegistrationResult(created: false);
            }

            $now = $this->clock->now();
            $lastIssuedAt = $this->tokens->latestCreatedAt($account->id, TokenPurpose::EMAIL_VERIFICATION);
            if ($lastIssuedAt !== null && $lastIssuedAt > $this->clock->format($now->subMinutes(self::VERIFICATION_RESEND_COOLDOWN_MINUTES))) {
                return new RegistrationResult(created: false);
            }

            $rawToken = $this->secureTokens->generate();
            $this->tokens->create(
                accountId: $account->id,
                purpose: TokenPurpose::EMAIL_VERIFICATION,
                tokenHash: $this->secureTokens->hash($rawToken),
                expiresAt: $this->clock->format($now->addHours(24)),
                now: $this->clock->format($now),
            );

            return new RegistrationResult(
                created: false,
                account: $account,
                verificationToken: $rawToken,
            );
        });
    }

    /**
     * MySQL and PostgreSQL use these SQLSTATEs for unique-key violations.
     * The only unique value inserted by registration is the normalized email.
     */
    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    public function verifyEmail(string $rawToken): Account|false
    {
        return $this->connection->transaction(function () use ($rawToken): Account|false {
            $now = $this->clock->databaseNow();
            $token = $this->tokens->lockUsable(
                $this->secureTokens->hash($rawToken),
                TokenPurpose::EMAIL_VERIFICATION,
                $now
            );

            if ($token === null) {
                return false;
            }

            $account = $this->accounts->lockRow((int) $token['account_id']);

            if ($account === null || $account['state'] !== AccountState::UNVERIFIED->value) {
                return false;
            }

            if (! $this->tokens->consume((int) $token['id'], $now)) {
                return false;
            }

            $this->accounts->update((int) $account['id'], [
                'state' => AccountState::PENDING_APPROVAL->value,
                'email_verified_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit->record(AuditEvent::EMAIL_VERIFIED, accountId: (int) $account['id']);

            return $this->accounts->requireById((int) $account['id']);
        });
    }

    public function authenticate(string $email, string $password): Account|false
    {
        try {
            $email = $this->emails->normalize($email);
        } catch (InvalidArgumentException) {
            $this->passwords->verify($password, $this->passwords->dummyHash());
            $this->audit->record(AuditEvent::LOGIN_FAILED, subjectIdentifier: mb_strtolower(trim($email), 'UTF-8'));

            return false;
        }

        $credentials = $this->accounts->findCredentialsByEmail($email);
        $hash = $credentials['password_hash'] ?? $this->passwords->dummyHash();
        $passwordValid = $this->passwords->verify($password, $hash);
        $account = $credentials['account'] ?? null;

        if (! $passwordValid || ! $account instanceof Account || ! $account->canAuthenticate()) {
            $this->audit->record(
                AuditEvent::LOGIN_FAILED,
                accountId: $account?->id,
                subjectIdentifier: $email,
            );

            return false;
        }

        if ($this->passwords->needsRehash($hash)) {
            $this->accounts->update($account->id, [
                'password_hash' => $this->passwords->hash($password),
                'updated_at' => $this->clock->databaseNow(),
            ]);
        }

        $this->audit->record(AuditEvent::LOGIN_SUCCEEDED, accountId: $account->id);

        return $this->accounts->requireById($account->id);
    }

    public function issuePasswordReset(string $email): ?string
    {
        // Spend the same dominant Argon2 verification cost before looking up
        // the account. Together with the controller's generic response this
        // prevents the unknown-account path from becoming a useful timing
        // oracle while keeping credential material out of the legacy table.
        $this->passwords->verify(hash('sha256', $email), $this->passwords->dummyHash());

        try {
            $email = $this->emails->normalize($email);
        } catch (InvalidArgumentException) {
            return null;
        }

        $account = $this->accounts->findByEmail($email);

        if ($account === null || ! $account->canAuthenticate()) {
            return null;
        }

        return $this->connection->transaction(function () use ($account, $email): string {
            $now = $this->clock->now();
            $rawToken = $this->secureTokens->generate();
            $this->tokens->expireOutstanding(
                $account->id,
                TokenPurpose::PASSWORD_RESET,
                $this->clock->format($now)
            );
            $this->tokens->create(
                accountId: $account->id,
                purpose: TokenPurpose::PASSWORD_RESET,
                tokenHash: $this->secureTokens->hash($rawToken),
                expiresAt: $this->clock->format($now->addMinutes(30)),
                now: $this->clock->format($now),
            );
            $this->audit->record(
                AuditEvent::PASSWORD_RESET_REQUESTED,
                accountId: $account->id,
                subjectIdentifier: $email,
            );

            return $rawToken;
        });
    }

    public function resetPassword(string $rawToken, string $password): bool
    {
        $passwordHash = $this->passwords->hash($password);

        return $this->connection->transaction(function () use ($rawToken, $passwordHash): bool {
            $now = $this->clock->databaseNow();
            $token = $this->tokens->lockUsable(
                $this->secureTokens->hash($rawToken),
                TokenPurpose::PASSWORD_RESET,
                $now
            );

            if ($token === null) {
                return false;
            }

            $account = $this->accounts->lockRow((int) $token['account_id']);

            if ($account === null || $account['state'] !== AccountState::ACTIVE->value) {
                return false;
            }

            if (! $this->tokens->consume((int) $token['id'], $now)) {
                return false;
            }

            $this->tokens->expireOutstanding((int) $account['id'], TokenPurpose::PASSWORD_RESET, $now);
            $this->sessions->revokeAll((int) $account['id'], $now);
            $this->accounts->update((int) $account['id'], [
                'password_hash' => $passwordHash,
                'password_changed_at' => $now,
                'session_version' => ((int) $account['session_version']) + 1,
                'updated_at' => $now,
            ]);

            if ($account['user_id'] !== null) {
                $this->connection->table('zp_user')
                    ->where('id', (int) $account['user_id'])
                    ->update([
                        'password' => '',
                        'pwReset' => '',
                        'pwResetExpiration' => null,
                        'status' => 'a',
                        'session' => '',
                        'sessiontime' => '',
                        'lastpwd_change' => $now,
                        'modified' => $now,
                    ]);
            }

            $this->audit->record(AuditEvent::PASSWORD_RESET_COMPLETED, accountId: (int) $account['id']);

            return true;
        });
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        if ($name === '' || mb_strlen($name, 'UTF-8') > 200) {
            throw new InvalidArgumentException('Name must contain between 1 and 200 characters.');
        }

        return $name;
    }
}
