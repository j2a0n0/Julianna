<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller as FrontcontrollerCore;
use Leantime\Domain\Auth\Services\Auth as AuthService;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;

class Logout extends Controller
{
    private AuthService $authService;

    private JuliannaAuth $juliannaAuth;

    /**
     * init - initialize private variables
     */
    public function init(AuthService $authService, JuliannaAuth $juliannaAuth): void
    {
        $this->authService = $authService;
        $this->juliannaAuth = $juliannaAuth;
    }

    /**
     * get - handle get requests
     */
    public function get(array $params): Response
    {
        if (is_numeric(session('julianna_auth.authenticated_account_id'))) {
            $this->juliannaAuth->revokeSession(session()->getId());
        }
        $this->authService->logout();
        SecureAuthRequest::clearPendingAuthentication();
        session()->forget([
            'julianna_auth.authenticated_account_id',
            'julianna_auth.session_version',
        ]);
        session()->invalidate();
        session()->regenerateToken();

        return FrontcontrollerCore::redirect(BASE_URL.'/');
    }
}
