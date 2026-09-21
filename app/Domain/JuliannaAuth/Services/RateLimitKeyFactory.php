<?php

namespace Leantime\Domain\JuliannaAuth\Services;

/**
 * Produces non-PII cache keys for the HTTP layer's rate limiter.
 */
class RateLimitKeyFactory
{
    public function registration(string $ipAddress): string
    {
        return 'julianna-auth:register:'.hash('sha256', trim($ipAddress));
    }

    public function loginIp(string $ipAddress): string
    {
        return 'julianna-auth:login-ip:'.hash('sha256', trim($ipAddress));
    }

    /**
     * Scope the email bucket to the source IP. A global per-email bucket lets
     * an attacker lock a known user out from another network, which is exactly
     * the denial-of-service behaviour Julianna's login policy avoids.
     */
    public function loginIdentity(string $ipAddress, string $normalizedEmail): string
    {
        return 'julianna-auth:login-identity:'.hash(
            'sha256',
            trim($ipAddress).'|'.mb_strtolower(trim($normalizedEmail), 'UTF-8')
        );
    }

    public function mfaIp(string $ipAddress): string
    {
        return 'julianna-auth:mfa-ip:'.hash('sha256', trim($ipAddress));
    }

    public function mfaAccount(int $accountId): string
    {
        return 'julianna-auth:mfa-account:'.hash('sha256', (string) $accountId);
    }
}
