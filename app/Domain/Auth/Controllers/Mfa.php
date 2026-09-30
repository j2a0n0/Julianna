<?php

namespace Leantime\Domain\Auth\Controllers;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Auth\Services\JuliannaSessionBridge;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Mfa extends Controller
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
        $account = $this->pendingAccount();
        if ($account === null) {
            return Frontcontroller::redirect(BASE_URL.'/auth/login');
        }

        $enrollment = $account->requiresMfaEnrollment();
        $secret = '';
        $qrData = null;

        if ($enrollment) {
            $secret = (string) session('julianna_auth.enrollment_secret', '');
            $uri = (string) session('julianna_auth.enrollment_uri', '');
            if ($secret === '' || $uri === '') {
                try {
                    $setup = $this->auth->beginTotp($account->id);
                } catch (Throwable) {
                    return Frontcontroller::redirect(BASE_URL.'/auth/login');
                }
                $secret = $setup->secret;
                $uri = $setup->provisioningUri;
                session()->put('julianna_auth.enrollment_secret', $secret);
                session()->put('julianna_auth.enrollment_uri', $uri);
            }
            $qrData = $this->qrDataUri($uri);
        }

        $this->tpl->assign('enrollment', $enrollment);
        $this->tpl->assign('secret', $secret);
        $this->tpl->assign('qrData', $qrData);
        $this->tpl->assign('redirectUrl', (string) session('julianna_auth.redirect', BASE_URL.'/agent'));

        return $this->tpl->display('auth.mfa', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/mfa');
        }

        $account = $this->pendingAccount();
        if ($account === null) {
            return Frontcontroller::redirect(BASE_URL.'/auth/login');
        }

        $code = is_string($params['code'] ?? null) ? trim($params['code']) : '';
        if ($account->requiresMfaEnrollment()) {
            $codes = $this->auth->confirmTotp($account->id, $code);
            if ($codes === false) {
                return $this->failed();
            }

            session()->forget(['julianna_auth.enrollment_secret', 'julianna_auth.enrollment_uri']);
            session()->put('julianna_auth.mfa_verified', true);
            session()->put('julianna_auth.recovery_codes', $codes);

            return Frontcontroller::redirect(BASE_URL.'/auth/recoveryCodes');
        }

        if (! $this->auth->verifyTotp($account->id, $code)) {
            return $this->failed();
        }

        $requestedRedirect = (string) session('julianna_auth.redirect', '');
        $this->sessions->establish($account);
        $redirect = $this->legacyAuth->resolveAuthenticatedRedirect($requestedRedirect);

        return Frontcontroller::redirect($redirect);
    }

    private function pendingAccount(): ?Account
    {
        $accountId = session('julianna_auth.account_id');
        if (! is_numeric($accountId)) {
            return null;
        }

        $account = $this->auth->account((int) $accountId);

        return $account !== null && $account->canAuthenticate() ? $account : null;
    }

    private function failed(): Response
    {
        $this->tpl->setNotification('notification.incorrect_twoFA_code', 'error');

        return Frontcontroller::redirect(BASE_URL.'/auth/mfa');
    }

    private function qrDataUri(string $uri): string
    {
        $result = (new PngWriter)->write(new QrCode(
            data: $uri,
            size: 260,
            margin: 10,
            backgroundColor: new Color(255, 255, 255),
        ));

        return $result->getDataUri();
    }
}
