<?php

namespace Leantime\Domain\Auth\Controllers;

use Illuminate\Support\Facades\Log;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Services\JuliannaAuthMailer;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Register extends Controller
{
    private JuliannaAuth $auth;

    private JuliannaAuthMailer $mailer;

    public function init(
        JuliannaAuth $auth,
        JuliannaAuthMailer $mailer,
    ): void {
        $this->auth = $auth;
        $this->mailer = $mailer;
    }

    public function get(array $params): Response
    {
        $this->tpl->assign('registrationAvailable', $this->mailer->registrationAvailable());
        $this->tpl->assign('values', []);

        return $this->tpl->display('auth.register', 'entry');
    }

    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/register');
        }

        if (! $this->mailer->registrationAvailable()) {
            $this->tpl->setNotification('text.registration_unavailable', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/register');
        }

        // A filled honeypot receives the same public outcome as a real request.
        if (trim((string) ($params['website'] ?? '')) !== '') {
            return $this->genericOutcome();
        }

        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $email = is_string($params['email'] ?? null) ? $params['email'] : '';
        $password = is_string($params['password'] ?? null) ? $params['password'] : '';
        $confirmation = is_string($params['password_confirmation'] ?? null) ? $params['password_confirmation'] : '';

        if (! hash_equals($password, $confirmation)) {
            $this->tpl->setNotification('notification.passwords_dont_match', 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/register');
        }

        try {
            $registration = $this->auth->register($name, $email, $password);
            if ($registration->account !== null && $registration->verificationToken !== null) {
                $this->mailer->sendVerification($registration->account, $registration->verificationToken);
            }
        } catch (\InvalidArgumentException $e) {
            $this->tpl->setNotification($e->getMessage(), 'error');

            return Frontcontroller::redirect(BASE_URL.'/auth/register');
        } catch (Throwable $e) {
            Log::error('Unable to process Julianna registration', ['exception' => $e]);
        }

        return $this->genericOutcome();
    }

    private function genericOutcome(): Response
    {
        $this->tpl->setNotification('notifications.registration_received', 'success');

        return Frontcontroller::redirect(BASE_URL.'/auth/pending');
    }
}
