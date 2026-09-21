<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use DomainException;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;

class SessionRegistry
{
    public function __construct(
        private AccountRepository $accounts,
        private SessionRepository $sessions,
        private AuthClock $clock,
    ) {}

    public function start(int $accountId, string $sessionId, int $lifetimeMinutes = 480): int
    {
        if ($sessionId === '') {
            throw new DomainException('A non-empty PHP session identifier is required.');
        }

        if ($lifetimeMinutes < 1) {
            throw new DomainException('Session lifetime must be at least one minute.');
        }

        $account = $this->accounts->requireById($accountId);

        if (! $account->canAuthenticate() || $account->mfaConfirmedAt === null) {
            throw new DomainException('A fully authenticated account is required to register a session.');
        }

        $now = $this->clock->now();
        $this->sessions->register(
            accountId: $accountId,
            sessionHash: $this->hash($sessionId),
            sessionVersion: $account->sessionVersion,
            expiresAt: $this->clock->format($now->addMinutes($lifetimeMinutes)),
            now: $this->clock->format($now),
        );

        return $account->sessionVersion;
    }

    public function isValid(int $accountId, string $sessionId, int $sessionVersion): bool
    {
        $account = $this->accounts->findById($accountId);

        return $account !== null
            && $account->canAuthenticate()
            && $account->mfaConfirmedAt !== null
            && $account->sessionVersion === $sessionVersion
            && $this->sessions->isValid(
                $accountId,
                $this->hash($sessionId),
                $sessionVersion,
                $this->clock->databaseNow()
            );
    }

    public function touch(string $sessionId, int $lifetimeMinutes = 480): void
    {
        if ($lifetimeMinutes < 1) {
            throw new DomainException('Session lifetime must be at least one minute.');
        }

        $now = $this->clock->now();
        $this->sessions->touch(
            $this->hash($sessionId),
            $this->clock->format($now),
            $this->clock->format($now->addMinutes($lifetimeMinutes)),
        );
    }

    public function revoke(string $sessionId): void
    {
        $this->sessions->revoke($this->hash($sessionId), $this->clock->databaseNow());
    }

    public function revokeAll(int $accountId): void
    {
        $this->sessions->revokeAll($accountId, $this->clock->databaseNow());
    }

    private function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
