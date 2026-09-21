<?php

namespace Leantime\Domain\Auth\Controllers;

use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

final class Pending extends Controller
{
    public function get(array $params): Response
    {
        return $this->tpl->display('auth.pending', 'entry');
    }
}
