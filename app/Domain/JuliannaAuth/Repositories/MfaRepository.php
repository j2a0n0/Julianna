<?php

namespace Leantime\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;

class MfaRepository
{
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    public function replaceSecret(int $accountId, string $encryptedSecret, string $now): void
    {
        $this->connection->table('julianna_auth_mfa')->updateOrInsert(
            ['account_id' => $accountId],
            [
                'encrypted_secret' => $encryptedSecret,
                'confirmed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $accountId, bool $forUpdate = false): ?array
    {
        $query = $this->connection->table('julianna_auth_mfa')
            ->where('account_id', $accountId);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row === null ? null : (array) $row;
    }

    public function confirm(int $accountId, string $now): void
    {
        $this->connection->table('julianna_auth_mfa')
            ->where('account_id', $accountId)
            ->update([
                'confirmed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  array<int, string>  $hashes
     */
    public function replaceRecoveryCodes(int $accountId, array $hashes, string $now): void
    {
        $this->connection->table('julianna_auth_recovery_codes')
            ->where('account_id', $accountId)
            ->delete();

        $rows = array_map(static fn (string $hash): array => [
            'account_id' => $accountId,
            'code_hash' => $hash,
            'consumed_at' => null,
            'created_at' => $now,
        ], $hashes);

        if ($rows !== []) {
            $this->connection->table('julianna_auth_recovery_codes')->insert($rows);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lockUnusedRecoveryCodes(int $accountId): array
    {
        return $this->connection->table('julianna_auth_recovery_codes')
            ->where('account_id', $accountId)
            ->whereNull('consumed_at')
            ->lockForUpdate()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    public function consumeRecoveryCode(int $id, string $now): bool
    {
        return $this->connection->table('julianna_auth_recovery_codes')
            ->where('id', $id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]) === 1;
    }
}
