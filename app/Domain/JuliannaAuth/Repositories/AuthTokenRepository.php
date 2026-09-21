<?php

namespace Leantime\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\TokenPurpose;

class AuthTokenRepository
{
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    public function create(
        int $accountId,
        TokenPurpose $purpose,
        string $tokenHash,
        string $expiresAt,
        string $now
    ): void {
        $this->connection->table('julianna_auth_tokens')->insert([
            'account_id' => $accountId,
            'purpose' => $purpose->value,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => $now,
        ]);
    }

    public function latestCreatedAt(int $accountId, TokenPurpose $purpose): ?string
    {
        $createdAt = $this->connection->table('julianna_auth_tokens')
            ->where('account_id', $accountId)
            ->where('purpose', $purpose->value)
            ->orderByDesc('created_at')
            ->value('created_at');

        return $createdAt === null ? null : (string) $createdAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockUsable(string $tokenHash, TokenPurpose $purpose, string $now): ?array
    {
        $row = $this->connection->table('julianna_auth_tokens')
            ->where('token_hash', $tokenHash)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }

    public function consume(int $tokenId, string $now): bool
    {
        return $this->connection->table('julianna_auth_tokens')
            ->where('id', $tokenId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]) === 1;
    }

    public function expireOutstanding(int $accountId, TokenPurpose $purpose, string $now): void
    {
        $this->connection->table('julianna_auth_tokens')
            ->where('account_id', $accountId)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]);
    }
}
