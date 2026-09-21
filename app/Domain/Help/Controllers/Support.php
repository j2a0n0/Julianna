<?php

namespace Leantime\Domain\Help\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Domain\Help\Services\Helper;

class Support extends Controller
{
    protected Helper $helpService;

    public function init(Helper $helpService)
    {
        $this->helpService = $helpService;

    }

    /**
     * get - handle get requests
     */
    public function get($params)
    {

        return Frontcontroller::redirect(BASE_URL.'/help/about');

    }
}
