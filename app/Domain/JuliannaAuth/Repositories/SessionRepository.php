<?php

namespace Leantime\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;

class SessionRepository
{
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    public function register(
        int $accountId,
        string $sessionHash,
        int $sessionVersion,
        string $expiresAt,
        string $now
    ): void {
        $this->connection->table('julianna_auth_sessions')->insert([
            'session_hash' => $sessionHash,
            'account_id' => $accountId,
            'session_version' => $sessionVersion,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    public function isValid(int $accountId, string $sessionHash, int $sessionVersion, string $now): bool
    {
        return $this->connection->table('julianna_auth_sessions')
            ->where('session_hash', $sessionHash)
            ->where('account_id', $accountId)
            ->where('session_version', $sessionVersion)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->exists();
    }

    public function touch(string $sessionHash, string $now, string $expiresAt): void
    {
        $this->connection->table('julianna_auth_sessions')
            ->where('session_hash', $sessionHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->update(['last_seen_at' => $now, 'expires_at' => $expiresAt]);
    }

    public function revoke(string $sessionHash, string $now): void
    {
        $this->connection->table('julianna_auth_sessions')
            ->where('session_hash', $sessionHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    public function revokeAll(int $accountId, string $now): void
    {
        $this->connection->table('julianna_auth_sessions')
            ->where('account_id', $accountId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }
}
