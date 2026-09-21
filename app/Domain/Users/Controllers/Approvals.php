<?php

namespace Leantime\Domain\Users\Controllers;

use Leantime\Core\Auth\Permissions\RequiresPermission;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\JuliannaAuthMailer;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Users\Permissions\UsersPermissions;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Approvals extends Controller
{
    private JuliannaAuth $auth;

    private JuliannaAuthMailer $mailer;

    private ProjectRepository $projects;

    public function init(
        JuliannaAuth $auth,
        JuliannaAuthMailer $mailer,
        ProjectRepository $projects,
    ): void {
        $this->auth = $auth;
        $this->mailer = $mailer;
        $this->projects = $projects;
    }

    #[RequiresPermission(UsersPermissions::APPROVE, global: true)]
    public function get(array $params): Response
    {
        $accounts = array_map(function ($account): array {
            return [
                'id' => $account->id,
                'name' => $account->displayName,
                'email' => $account->email,
                'email_verified_at' => $account->emailVerifiedAt,
                'audit' => $this->auth->audit($account->id),
            ];
        }, $this->auth->pendingApproval());

        $roles = array_filter(
            Roles::getRoles(),
            static fn (string $name, int $level): bool => $level <= 30,
            ARRAY_FILTER_USE_BOTH,
        );

        $this->tpl->assign('accounts', $accounts);
        $this->tpl->assign('roles', $roles);
        $this->tpl->assign('projects', $this->projects->getAll());

        return $this->tpl->display('users.approvals');
    }

    #[RequiresPermission(UsersPermissions::APPROVE, global: true)]
    public function post(array $params): Response
    {
        if (! SecureAuthRequest::hasValidCsrf($params)) {
            $this->tpl->setNotification('notification.form_token_incorrect', 'error');

            return Frontcontroller::redirect(BASE_URL.'/users/approvals');
        }

        $accountId = filter_var($params['account_id'] ?? null, FILTER_VALIDATE_INT);
        $action = is_string($params['action'] ?? null) ? $params['action'] : '';
        if ($accountId === false || $accountId < 1 || ! in_array($action, ['approve', 'reject'], true)) {
            $this->tpl->setNotification('notifications.approval_failed', 'error');

            return Frontcontroller::redirect(BASE_URL.'/users/approvals');
        }

        try {
            if ($action === 'approve') {
                $role = is_string($params['role'] ?? null) ? $params['role'] : '';
                $projects = is_array($params['projects'] ?? null) ? $params['projects'] : [];
                $result = $this->auth->approve((int) $accountId, $role, $projects, (int) session('userdata.id'));
                $this->mailer->sendApprovalDecision($result->account, true);
            } else {
                $account = $this->auth->reject((int) $accountId, (int) session('userdata.id'));
                $this->mailer->sendApprovalDecision($account, false);
            }

            $this->tpl->setNotification('notifications.approval_saved', 'success');
        } catch (Throwable $e) {
            report($e);
            $this->tpl->setNotification('notifications.approval_failed', 'error');
        }

        return Frontcontroller::redirect(BASE_URL.'/users/approvals');
    }
}
