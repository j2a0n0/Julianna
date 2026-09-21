<?php

namespace Leantime\Domain\JuliannaAuth\Enums;

enum AuditEvent: string
{
    case SIGNUP = 'signup';
    case EMAIL_VERIFIED = 'email_verified';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case LOGIN_SUCCEEDED = 'login_succeeded';
    case LOGIN_FAILED = 'login_failed';
    case PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    case PASSWORD_RESET_COMPLETED = 'password_reset_completed';
    case MFA_ENROLLMENT_STARTED = 'mfa_enrollment_started';
    case MFA_ENABLED = 'mfa_enabled';
    case MFA_VERIFIED = 'mfa_verified';
    case MFA_FAILED = 'mfa_failed';
    case MFA_RECOVERY_USED = 'mfa_recovery_used';
    case MFA_RECOVERY_FAILED = 'mfa_recovery_failed';
    case ACCOUNT_DISABLED = 'account_disabled';
}
