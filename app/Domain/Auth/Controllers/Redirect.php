<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects to the OAuth provider for authentication.
 */
class Redirect extends Controller
{
    /**
     * Redirects to the GitHub OAuth login page.
     *
     * @param  array  $params  Request parameters
     */
    public function get(array $params): Response
    {
        return Frontcontroller::redirect(BASE_URL.'/auth/login');
    }
}
