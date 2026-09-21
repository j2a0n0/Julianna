<?php

namespace Leantime\Domain\Oidc\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Symfony\Component\HttpFoundation\Response;

class Login extends Controller
{
    /**
     * Redirects to the OIDC provider login page.
     *
     * @param  array  $params  Request parameters
     */
    public function get(array $params): Response
    {
        // Julianna 1.0 has one identity authority: its local password + MFA
        // service. The legacy OIDC implementation remains only as internal
        // source history and cannot initiate authentication.
        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
