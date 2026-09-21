<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Symfony\Component\HttpFoundation\Response;

/** Retained only so historical links fail closed instead of invoking legacy credentials. */
final class UserInvite extends Controller
{
    public function get(array $params): Response
    {
        return Frontcontroller::redirect(BASE_URL.'/auth/register');
    }

    public function post(array $params): Response
    {
        return Frontcontroller::redirect(BASE_URL.'/auth/register');
    }
}
