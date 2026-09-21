<?php

namespace Leantime\Domain\JuliannaAuth\Enums;

enum TokenPurpose: string
{
    case EMAIL_VERIFICATION = 'email_verification';
    case PASSWORD_RESET = 'password_reset';
}
