<?php

namespace Leantime\Domain\Notifications\Hxcontrollers;

use Leantime\Core\Controller\HtmxController;

class News extends HtmxController
{
    protected static string $view = 'notifications::partials.latestNews';

    public function get()
    {
        $this->tpl->assign('rss', false);
    }
}
