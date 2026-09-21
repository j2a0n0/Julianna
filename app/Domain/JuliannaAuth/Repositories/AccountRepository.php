<?php

namespace Leantime\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Models\Account;
use RuntimeException;

class AccountRepository
{
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    public function create(string $email, string $displayName, string $passwordHash, string $now): Account
    {
        $id = (int) $this->connection->table('julianna_auth_accounts')->insertGetId([
            'user_id' => null,
            'email_normalized' => $email,
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'state' => AccountState::UNVERIFIED->value,
            'email_verified_at' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'disabled_at' => null,
            'mfa_confirmed_at' => null,
            'password_changed_at' => $now,
            'session_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireById($id);
    }

    public function findById(int $accountId): ?Account
    {
        $row = $this->connection->table('julianna_auth_accounts')
            ->where('id', $accountId)
            ->first();

        return $row === null ? null : Account::fromRow($row);
    }

    public function requireById(int $accountId): Account
    {
        return $this->findById($accountId)
            ?? throw new RuntimeException('Julianna account not found.');
    }

    public function findByEmail(string $normalizedEmail): ?Account
    {
        $row = $this->connection->table('julianna_auth_accounts')
            ->where('email_normalized', $normalizedEmail)
            ->first();

        return $row === null ? null : Account::fromRow($row);
    }

    public function findByUserId(int $userId): ?Account
    {
        $row = $this->connection->table('julianna_auth_accounts')
            ->where('user_id', $userId)
            ->first();

        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * Returns the password hash only to the authentication service. Public DTOs
     * deliberately never expose credential material.
     *
     * @return array{account: Account, password_hash: string}|null
     */
    public function findCredentialsByEmail(string $normalizedEmail): ?array
    {
        $row = $this->connection->table('julianna_auth_accounts')
            ->where('email_normalized', $normalizedEmail)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'account' => Account::fromRow($row),
            'password_hash' => (string) $row->password_hash,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockRow(int $accountId): ?array
    {
        $row = $this->connection->table('julianna_auth_accounts')
            ->where('id', $accountId)
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(int $accountId, array $values): void
    {
        $this->connection->table('julianna_auth_accounts')
            ->where('id', $accountId)
            ->update($values);
    }

    /**
     * @return array<int, Account>
     */
    public function pendingApproval(): array
    {
        return $this->connection->table('julianna_auth_accounts')
            ->where('state', AccountState::PENDING_APPROVAL->value)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (object $row): Account => Account::fromRow($row))
            ->all();
    }
}
