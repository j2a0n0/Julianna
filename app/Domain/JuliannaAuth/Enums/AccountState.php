<?php

namespace Leantime\Domain\JuliannaAuth\Enums;

enum AccountState: string
{
    case UNVERIFIED = 'unverified';
    case PENDING_APPROVAL = 'pending_approval';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case DISABLED = 'disabled';

    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
