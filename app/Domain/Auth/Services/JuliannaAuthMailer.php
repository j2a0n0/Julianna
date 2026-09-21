<?php

namespace Leantime\Domain\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Language;
use Leantime\Core\Mailer;
use Leantime\Domain\JuliannaAuth\Models\Account;

final class JuliannaAuthMailer
{
    public function __construct(
        private readonly Environment $config,
        private readonly Language $language,
    ) {}

    public function registrationAvailable(): bool
    {
        if (! (bool) ($this->config->registrationEnabled ?? true)) {
            return false;
        }

        if (($this->config->env ?? 'production') !== 'production') {
            return true;
        }

        return $this->smtpConfigurationReady();
    }

    public function smtpConfigurationReady(): bool
    {
        if (! (bool) ($this->config->useSMTP ?? false)) {
            return false;
        }

        $port = (int) ($this->config->smtpPort ?? 0);
        if (
            trim((string) ($this->config->smtpHosts ?? '')) === ''
            || filter_var((string) ($this->config->email ?? ''), FILTER_VALIDATE_EMAIL) === false
            || $port < 1
            || $port > 65535
        ) {
            return false;
        }

        if ((bool) ($this->config->smtpAuth ?? true)) {
            return trim((string) ($this->config->smtpUsername ?? '')) !== ''
                && trim((string) ($this->config->smtpPassword ?? '')) !== '';
        }

        return true;
    }

    public function sendVerification(Account $account, string $rawToken): void
    {
        $url = BASE_URL.'/auth/verifyEmail/'.rawurlencode($rawToken);
        $this->send(
            [$account->email],
            $this->language->__('email_notifications.julianna_verify_subject'),
            sprintf($this->language->__('email_notifications.julianna_verify_message'), $url),
            'email_verification',
        );
    }

    public function sendPasswordReset(string $email, string $rawToken): void
    {
        $url = BASE_URL.'/auth/resetPw/'.rawurlencode($rawToken);
        $this->send(
            [$email],
            $this->language->__('email_notifications.password_reset_subject'),
            sprintf($this->language->__('email_notifications.julianna_reset_message'), $url),
            'password_reset',
        );
    }

    public function notifyApprovers(Account $account): void
    {
        $recipients = DB::table('zp_user')
            ->whereRaw('LOWER(status) = ?', ['a'])
            ->whereIn('role', [40, 50, '40', '50'])
            ->pluck('username')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $url = BASE_URL.'/users/approvals';
        $this->send(
            $recipients,
            $this->language->__('email_notifications.julianna_approval_subject'),
            sprintf($this->language->__('email_notifications.julianna_approval_message'), e($account->displayName), e($account->email), $url),
            'signup_approval',
        );
    }

    public function sendApprovalDecision(Account $account, bool $approved): void
    {
        $subjectKey = $approved
            ? 'email_notifications.julianna_approved_subject'
            : 'email_notifications.julianna_rejected_subject';
        $messageKey = $approved
            ? 'email_notifications.julianna_approved_message'
            : 'email_notifications.julianna_rejected_message';

        $this->send(
            [$account->email],
            $this->language->__($subjectKey),
            sprintf($this->language->__($messageKey), BASE_URL.'/auth/login'),
            'signup_decision',
        );
    }

    private function send(array $recipients, string $subject, string $html, string $context): void
    {
        // Development installations are allowed to operate without an SMTP
        // relay. Keep the full message in the local application log rather
        // than handing authentication links to the host's mail transport.
        if (($this->config->env ?? 'production') !== 'production' && ! (bool) ($this->config->useSMTP ?? false)) {
            Log::info('Julianna development mail', [
                'to' => $recipients,
                'subject' => $subject,
                'html' => $html,
                'context' => $context,
            ]);

            return;
        }

        $mailer = app()->make(Mailer::class);
        $mailer->setContext($context);
        $mailer->setSubject($subject);
        $mailer->setHtml($html);
        $mailer->sendMail($recipients, 'Julianna');
    }
}
