<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Auth\Services\JuliannaSessionBridge;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;

final class RecoveryCodes extends Controller
{
    private JuliannaAuth $auth;

    private JuliannaSessionBridge $sessions;

    private Auth $legacyAuth;

    public function init(
        JuliannaAuth $auth,
        JuliannaSessionBridge $sessions,
        Auth $legacyAuth,
    ): void {
        $this->auth = $auth;
        $this->sessions = $sessions;
        $this->legacyAuth = $legacyAuth;
    }

    public function get(array $params): Response
    {
        $account = $this->verifiedAccount();
        $codes = session('julianna_auth.recovery_codes');
        if ($account === null || ! is_array($codes) || $codes === []) {
            return Frontcontroller::redirect(BASE_URL.'/auth/login');
        }

        $this->tpl->assign('recoveryCodes', $codes);

        return $this->tpl->display('auth.recoveryCodes', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params) || (string) ($params['saved'] ?? '') !== '1') {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/recoveryCodes');
        }

        $account = $this->verifiedAccount();
        if ($account === null || ! is_array(session('julianna_auth.recovery_codes'))) {
            return Frontcontroller::redirect(BASE_URL.'/auth/login');
        }

        $requestedRedirect = (string) session('julianna_auth.redirect', '');
        $this->sessions->establish($account);
        $redirect = $this->legacyAuth->resolveAuthenticatedRedirect($requestedRedirect);

        return Frontcontroller::redirect($redirect);
    }

    private function verifiedAccount(): ?Account
    {
        $accountId = session('julianna_auth.account_id');
        if (! is_numeric($accountId) || session('julianna_auth.mfa_verified') !== true) {
            return null;
        }

        $account = $this->auth->account((int) $accountId);

        return $account !== null && $account->mfaConfirmedAt !== null ? $account : null;
    }
}
