<?php

namespace Leantime\Domain\Auth\Controllers;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Services\JuliannaAuthMailer;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResetPw extends Controller
{
    private JuliannaAuth $auth;

    private JuliannaAuthMailer $mailer;

    public function init(JuliannaAuth $auth, JuliannaAuthMailer $mailer): void
    {
        $this->auth = $auth;
        $this->mailer = $mailer;
    }

    public function get(array $params): Response
    {
        // Token validity is deliberately not disclosed on GET. Any token-shaped
        // path gets the same form; single use and expiry are enforced on submit.
        if (is_string($params['id'] ?? null) && $params['id'] !== '') {
            return $this->tpl->display('auth.resetPw', 'entry');
        }

        return $this->tpl->display('auth.requestPwLink', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/resetPw');
        }

        if (is_string($params['username'] ?? null)) {
            $email = mb_strtolower(trim($params['username']), 'UTF-8');
            try {
                $token = $this->auth->issuePasswordReset($email);
                if ($token !== null) {
                    $this->mailer->sendPasswordReset($email, $token);
                }
            } catch (Throwable $e) {
                Log::error('Unable to process password-reset request', ['exception' => $e]);
            }

            // Always the same response, including malformed and unknown email.
            $this->tpl->setNotification('notifications.email_was_sent_to_reset', 'success');

            return Frontcontroller::redirect(BASE_URL.'/auth/resetPw');
        }

        $token = is_string($params['id'] ?? null) ? $params['id'] : '';
        $password = is_string($params['password'] ?? null) ? $params['password'] : '';
        $confirmation = is_string($params['password2'] ?? null) ? $params['password2'] : '';
        if ($password === '' || ! hash_equals($password, $confirmation)) {
            $this->tpl->setNotification('notification.passwords_dont_match', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/resetPw/'.rawurlencode($token));
        }

        try {
            $changed = $token !== '' && $this->auth->resetPassword($token, $password);
        } catch (InvalidArgumentException $e) {
            $this->tpl->setNotification($e->getMessage(), 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/resetPw/'.rawurlencode($token));
        } catch (Throwable $e) {
            Log::error('Unable to complete password reset', ['exception' => $e]);
            $changed = false;
        }

        // Keep the public response identical for valid, invalid, expired, and
        // reused tokens. The password is changed only on the valid path above.
        $this->tpl->setNotification('notifications.password_reset_processed', 'info');

        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
