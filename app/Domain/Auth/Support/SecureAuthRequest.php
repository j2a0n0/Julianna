<?php

namespace Leantime\Domain\Auth\Support;

use Leantime\Core\Http\IncomingRequest;

/**
 * Small, framework-independent guards shared by Julianna's convention-routed
 * authentication controllers. Global CSRF enforcement cannot yet be enabled
 * for the legacy application, so every new state-changing auth action calls
 * this class explicitly.
 */
final class SecureAuthRequest
{
    public static function hasValidCsrf(array $params): bool
    {
        $expected = (string) session()->token();
        $provided = $params['_token'] ?? '';

        return is_string($provided)
            && $expected !== ''
            && hash_equals($expected, $provided);
    }

    public static function clientIp(IncomingRequest $request): string
    {
        return (string) ($request->getClientIp() ?: 'unknown');
    }

    public static function userAgent(IncomingRequest $request): string
    {
        return mb_substr((string) $request->userAgent(), 0, 500);
    }

    public static function rotateSession(): void
    {
        session()->migrate(true);
        session()->regenerateToken();
    }

    /**
     * Personal API credentials may only be minted from a fully established,
     * MFA-backed browser session. Request-scoped API userdata deliberately
     * lacks these registry markers.
     */
    public static function hasFullWebAuthentication(): bool
    {
        return session()->exists('userdata')
            && is_numeric(session('julianna_auth.authenticated_account_id'))
            && is_numeric(session('julianna_auth.session_version'))
            && ! session()->exists('julianna_auth.account_id');
    }

    public static function clearPendingAuthentication(): void
    {
        session()->forget([
            'julianna_auth.account_id',
            'julianna_auth.user_id',
            'julianna_auth.redirect',
            'julianna_auth.enrollment_secret',
            'julianna_auth.enrollment_uri',
            'julianna_auth.mfa_verified',
            'julianna_auth.recovery_codes',
        ]);
    }
}
