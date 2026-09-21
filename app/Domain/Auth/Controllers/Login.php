<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Services\Auth as LegacyAuth;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;

/** Password authentication is only the first half of a Julianna web login. */
final class Login extends Controller
{
    private LegacyAuth $legacyAuth;

    private JuliannaAuth $auth;

    public function init(LegacyAuth $legacyAuth, JuliannaAuth $auth): void
    {
        $this->legacyAuth = $legacyAuth;
        $this->auth = $auth;
    }

    public function get(array $params): Response
    {
        self::dispatchEvent('beforeAuth', $params);
        $filtered = self::dispatchFilter('beforeAuthHandling', $params);
        if ($filtered instanceof Response) {
            return $filtered;
        }

        $rawRedirect = $_GET['redirect'] ?? null;
        $redirect = $this->legacyAuth->resolveSafeRedirect(is_string($rawRedirect) ? $rawRedirect : null);

        $this->tpl->assign('inputPlaceholder', 'input.placeholders.enter_email');
        $this->tpl->assign('redirectUrl', urlencode($redirect));
        // Julianna's first release has one enforced identity flow. Legacy LDAP
        // and OIDC settings cannot create a session that bypasses mandatory MFA.
        $this->tpl->assign('oidcEnabled', false);
        $this->tpl->assign('noLoginForm', false);

        return $this->tpl->display('auth.login', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            return $this->failed();
        }

        $email = is_string($params['username'] ?? null) ? $params['username'] : '';
        $password = is_string($params['password'] ?? null) ? $params['password'] : '';
        $rawRedirect = $params['redirectUrl'] ?? null;
        $redirect = $this->legacyAuth->resolveSafeRedirect(
            is_string($rawRedirect) ? urldecode($rawRedirect) : null
        );

        self::dispatchEvent('beforeAuthServiceCall', ['post' => $params]);
        $account = $this->auth->authenticate($email, $password);
        if ($account === false) {
            return $this->failed();
        }

        // Re-authenticating in an existing browser revokes its previous local
        // application state before the primary-authentication session rotation.
        if (session()->exists('userdata')) {
            $oldAccount = session('julianna_auth.authenticated_account_id');
            if (is_numeric($oldAccount)) {
                $this->auth->revokeSession(session()->getId());
            }
            $this->legacyAuth->logout();
        }

        SecureAuthRequest::clearPendingAuthentication();
        session()->forget(['julianna_auth.authenticated_account_id', 'julianna_auth.session_version']);
        SecureAuthRequest::rotateSession();
        session()->put('julianna_auth.account_id', $account->id);
        session()->put('julianna_auth.user_id', $account->userId);
        session()->put('julianna_auth.redirect', $redirect);

        self::dispatchEvent('successfulLogin', ['post' => ['username' => $email]]);

        return Frontcontroller::redirect(BASE_URL.'/auth/mfa');
    }

    private function failed(): Response
    {
        // Identical for unknown, unverified, pending, rejected, disabled and
        // wrong-password accounts to prevent state and account enumeration.
        $this->tpl->setNotification('notifications.username_or_password_incorrect', 'error');

        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
