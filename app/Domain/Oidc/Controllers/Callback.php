<?php

namespace Leantime\Domain\Oidc\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Symfony\Component\HttpFoundation\Response;

class Callback extends Controller
{
    public function get($params): Response
    {
        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
