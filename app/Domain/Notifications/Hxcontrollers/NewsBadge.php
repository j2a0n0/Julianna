<?php

namespace Leantime\Domain\Notifications\Hxcontrollers;

use Leantime\Core\Controller\HtmxController;

class NewsBadge extends HtmxController
{
    protected static string $view = 'notifications::partials.newsBadge';

    public function get()
    {
        $this->tpl->assign('hasNews', false);
    }
}
