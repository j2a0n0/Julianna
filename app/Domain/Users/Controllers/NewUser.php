<?php

namespace Leantime\Domain\Users\Controllers;

use Leantime\Core\Auth\Permissions\RequiresPermission;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Users\Permissions\UsersPermissions;
use Symfony\Component\HttpFoundation\Response;

/** Legacy direct invitations are intentionally unavailable in Julianna. */
final class NewUser extends Controller
{
    #[RequiresPermission(UsersPermissions::CREATE, global: true)]
    public function get(array $params): Response
    {
        $this->tpl->setNotification('notifications.use_signup_approval', 'info');

        return Frontcontroller::redirect(BASE_URL.'/users/approvals');
    }

    #[RequiresPermission(UsersPermissions::CREATE, global: true)]
    public function post(array $params): Response
    {
        return $this->get($params);
    }
}
