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

final class Recovery extends Controller
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
        if ($this->pendingAccount() === null) {
            return Frontcontroller::redirect(BASE_URL.'/auth/login');
        }

        return $this->tpl->display('auth.recovery', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/recovery');
        }

        $account = $this->pendingAccount();
        $code = is_string($params['code'] ?? null) ? $params['code'] : '';
        if ($account === null || ! $this->auth->verifyRecoveryCode($account->id, $code)) {
            $this->tpl->setNotification('notifications.invalid_recovery_code', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/recovery');
        }

        $redirect = $this->legacyAuth->resolveSafeRedirect((string) session('julianna_auth.redirect', ''));
        $this->sessions->establish($account);

        return Frontcontroller::redirect($redirect);
    }

    private function pendingAccount(): ?Account
    {
        $accountId = session('julianna_auth.account_id');

        return is_numeric($accountId) ? $this->auth->account((int) $accountId) : null;
    }
}
