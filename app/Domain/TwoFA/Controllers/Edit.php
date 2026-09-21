<?php

namespace Leantime\Domain\TwoFA\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Julianna MFA is mandatory and managed by the independent identity service.
 * The legacy settings endpoint remains as a redirect for old bookmarks only.
 */
final class Edit extends Controller
{
    public function get(array $params): Response
    {
        $this->tpl->setNotification('notifications.mfa_is_mandatory', 'info');

        return Frontcontroller::redirect(BASE_URL.'/users/editOwn#security');
    }

    public function post(array $params): Response
    {
        return $this->get($params);
    }
}
