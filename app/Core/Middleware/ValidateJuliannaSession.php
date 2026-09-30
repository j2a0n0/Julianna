<?php

namespace Leantime\Core\Middleware;

use Closure;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Core\Http\IncomingRequest;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces administrative disablement and password-reset session revocation
 * for fully authenticated browser sessions. API credentials remain separately
 * revocable and intentionally do not use this browser-session registry.
 */
final class ValidateJuliannaSession
{
    public function __construct(
        private readonly JuliannaAuth $juliannaAuth,
        private readonly Auth $legacyAuth,
        private readonly Environment $config,
    ) {}

    public function handle(IncomingRequest $request, Closure $next): Response
    {
        // MCP runs with a throwaway token-authenticated session. Ignore any
        // accompanying browser cookie; AuthCheck authenticates the token anew.
        if ($request->isMcpRequest()) {
            return $next($request);
        }

        $hasWebUser = session()->exists('userdata');

        // Stateless API credentials are independently revocable and establish
        // their request-scoped userdata later in AuthCheck. A browser AJAX/API
        // request already carrying a cookie session must still be validated so
        // password resets and administrative disablement take effect at once.
        if (($request->isApiOrCronRequest() || $request->isMcpRequest()) && ! $hasWebUser) {
            return $next($request);
        }

        $accountId = session('julianna_auth.authenticated_account_id');
        $version = session('julianna_auth.session_version');
        $hasAccountId = is_numeric($accountId);
        $hasVersion = is_numeric($version);

        if (! $hasWebUser && ! $hasAccountId && ! $hasVersion) {
            return $next($request);
        }

        // A legacy/OIDC/LDAP-created userdata session has no Julianna registry
        // markers. Reject it instead of silently admitting an MFA bypass.
        if (
            ! $hasWebUser
            || ! $hasAccountId
            || ! $hasVersion
            || ! $this->juliannaAuth->validateSession((int) $accountId, session()->getId(), (int) $version)
        ) {
            return $this->invalidateSession();
        }

        $this->juliannaAuth->touchSession(
            session()->getId(),
            max(1, (int) ($this->config->sessionExpiration ?? 480)),
        );
        // StartSession skips storage writes for pure reads. Record activity so
        // the underlying PHP/Laravel session's idle TTL slides as well.
        session()->put('julianna_auth.last_seen_at', microtime(true));

        return $next($request);
    }

    private function invalidateSession(): Response
    {
        try {
            $this->legacyAuth->logout();
        } finally {
            SecureAuthRequest::clearPendingAuthentication();
            session()->forget([
                'userdata',
                'julianna_auth.authenticated_account_id',
                'julianna_auth.session_version',
            ]);
            session()->invalidate();
            session()->regenerateToken();
        }

        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
