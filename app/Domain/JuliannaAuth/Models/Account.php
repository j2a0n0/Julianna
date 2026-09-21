<?php

namespace Leantime\Domain\JuliannaAuth\Models;

use Leantime\Domain\JuliannaAuth\Enums\AccountState;

final readonly class Account
{
    public function __construct(
        public int $id,
        public string $email,
        public string $displayName,
        public AccountState $state,
        public ?int $userId,
        public ?string $emailVerifiedAt,
        public ?string $approvedAt,
        public ?string $mfaConfirmedAt,
        public int $sessionVersion,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;

        return new self(
            id: (int) $row['id'],
            email: (string) $row['email_normalized'],
            displayName: (string) $row['display_name'],
            state: AccountState::from((string) $row['state']),
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            emailVerifiedAt: $row['email_verified_at'] ?? null,
            approvedAt: $row['approved_at'] ?? null,
            mfaConfirmedAt: $row['mfa_confirmed_at'] ?? null,
            sessionVersion: (int) ($row['session_version'] ?? 1),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    public function canAuthenticate(): bool
    {
        return $this->state->canAuthenticate() && $this->userId !== null;
    }

    public function requiresMfaEnrollment(): bool
    {
        return $this->canAuthenticate() && $this->mfaConfirmedAt === null;
    }
}
