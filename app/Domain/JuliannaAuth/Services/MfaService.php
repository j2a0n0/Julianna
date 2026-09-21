<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\AuditEvent;
use Leantime\Domain\JuliannaAuth\Models\TotpEnrollment;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\MfaRepository;
use RobThree\Auth\TwoFactorAuth;
use RuntimeException;

class MfaService
{
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private ConnectionInterface $connection,
        private AccountRepository $accounts,
        private MfaRepository $mfa,
        private Encrypter $encrypter,
        private PasswordService $passwords,
        private AuditLog $audit,
        private AuthClock $clock,
    ) {}

    public function begin(int $accountId): TotpEnrollment
    {
        $account = $this->accounts->requireById($accountId);

        if (! $account->canAuthenticate() || $account->mfaConfirmedAt !== null) {
            throw new DomainException('TOTP enrollment is only available to active accounts without MFA.');
        }

        $totp = $this->totp();
        $secret = $totp->createSecret(160);
        $this->mfa->replaceSecret(
            $accountId,
            $this->encrypter->encrypt($secret, false),
            $this->clock->databaseNow()
        );
        $this->audit->record(AuditEvent::MFA_ENROLLMENT_STARTED, accountId: $accountId);

        return new TotpEnrollment(
            secret: $secret,
            provisioningUri: $totp->getQRText($account->email, $secret),
        );
    }

    /**
     * Confirms enrollment and returns ten recovery codes exactly once.
     *
     * @return array<int, string>|false
     */
    public function confirm(int $accountId, string $code): array|false
    {
        return $this->connection->transaction(function () use ($accountId, $code): array|false {
            $accountRow = $this->accounts->lockRow($accountId);
            $mfaRow = $this->mfa->find($accountId, forUpdate: true);

            if (
                $accountRow === null
                || $accountRow['state'] !== 'active'
                || $accountRow['user_id'] === null
                || $accountRow['mfa_confirmed_at'] !== null
                || $mfaRow === null
            ) {
                return false;
            }

            $secret = $this->decryptSecret((string) $mfaRow['encrypted_secret']);

            if (! $this->totp()->verifyCode($secret, trim($code))) {
                $this->audit->record(AuditEvent::MFA_FAILED, accountId: $accountId, context: ['stage' => 'enrollment']);

                return false;
            }

            $rawCodes = [];
            $hashes = [];
            for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
                $rawCode = $this->newRecoveryCode();
                $rawCodes[] = $rawCode;
                $hashes[] = $this->passwords->hash($this->normalizeRecoveryCode($rawCode));
            }

            $now = $this->clock->databaseNow();
            $this->mfa->confirm($accountId, $now);
            $this->mfa->replaceRecoveryCodes($accountId, $hashes, $now);
            $this->accounts->update($accountId, [
                'mfa_confirmed_at' => $now,
                'updated_at' => $now,
            ]);

            if ($accountRow['user_id'] !== null) {
                $this->connection->table('zp_user')
                    ->where('id', (int) $accountRow['user_id'])
                    ->update([
                        'twoFAEnabled' => 1,
                        // Never copy the plaintext secret into the legacy table.
                        'twoFASecret' => null,
                        'modified' => $now,
                    ]);
            }

            $this->audit->record(AuditEvent::MFA_ENABLED, accountId: $accountId);

            return $rawCodes;
        });
    }

    public function verify(int $accountId, string $code): bool
    {
        $account = $this->accounts->requireById($accountId);
        $mfa = $this->mfa->find($accountId);

        if (! $account->canAuthenticate() || $account->mfaConfirmedAt === null || $mfa === null || $mfa['confirmed_at'] === null) {
            return false;
        }

        $verified = $this->totp()->verifyCode(
            $this->decryptSecret((string) $mfa['encrypted_secret']),
            trim($code)
        );
        $this->audit->record(
            $verified ? AuditEvent::MFA_VERIFIED : AuditEvent::MFA_FAILED,
            accountId: $accountId,
        );

        return $verified;
    }

    public function verifyRecoveryCode(int $accountId, string $code): bool
    {
        try {
            $code = $this->normalizeRecoveryCode($code);
        } catch (DomainException) {
            $this->audit->record(AuditEvent::MFA_RECOVERY_FAILED, accountId: $accountId);

            return false;
        }

        return $this->connection->transaction(function () use ($accountId, $code): bool {
            $account = $this->accounts->requireById($accountId);

            if (! $account->canAuthenticate() || $account->mfaConfirmedAt === null) {
                return false;
            }

            $matchedId = null;
            foreach ($this->mfa->lockUnusedRecoveryCodes($accountId) as $row) {
                if ($this->passwords->verify($code, (string) $row['code_hash']) && $matchedId === null) {
                    $matchedId = (int) $row['id'];
                }
            }

            if ($matchedId === null || ! $this->mfa->consumeRecoveryCode($matchedId, $this->clock->databaseNow())) {
                $this->audit->record(AuditEvent::MFA_RECOVERY_FAILED, accountId: $accountId);

                return false;
            }

            $this->audit->record(AuditEvent::MFA_RECOVERY_USED, accountId: $accountId);

            return true;
        });
    }

    private function decryptSecret(string $encryptedSecret): string
    {
        $secret = $this->encrypter->decrypt($encryptedSecret, false);

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stored TOTP secret is invalid.');
        }

        return $secret;
    }

    private function totp(): TwoFactorAuth
    {
        return new TwoFactorAuth('Julianna', 6, 30, 'sha1');
    }

    private function newRecoveryCode(): string
    {
        return implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
    }

    private function normalizeRecoveryCode(string $code): string
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));

        if (preg_match('/^[A-F0-9]{16}$/', $normalized) !== 1) {
            throw new DomainException('Invalid recovery code format.');
        }

        return $normalized;
    }
}
