<?php

namespace Leantime\Domain\Auth\Controllers;

use Illuminate\Support\Facades\Log;
use Leantime\Core\Controller\Controller;
use Leantime\Domain\Auth\Services\JuliannaAuthMailer;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class VerifyEmail extends Controller
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
        $token = is_string($params['id'] ?? null) ? $params['id'] : '';

        try {
            $account = $token === '' ? false : $this->auth->verifyEmail($token);
            if ($account !== false) {
                $this->mailer->notifyApprovers($account);
            }
        } catch (Throwable $e) {
            Log::error('Unable to verify Julianna email', ['exception' => $e]);
        }

        // Deliberately identical for invalid, expired, reused and valid links.
        return $this->tpl->display('auth.verifyEmail', 'entry');
    }
}
