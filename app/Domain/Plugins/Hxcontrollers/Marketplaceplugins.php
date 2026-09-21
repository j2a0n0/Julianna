<?php

namespace Leantime\Domain\Plugins\Hxcontrollers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Leantime\Core\Controller\HtmxController;

class Marketplaceplugins extends HtmxController
{
    protected static string $view = 'plugins::partials.pluginlist';

    /**
     * @throws BindingResolutionException
     */
    public function getlist(): void
    {
        $this->tpl->assign('plugins', []);
    }

    public function getLatest()
    {
        $this->tpl->assign('plugins', []);

        return $this->tpl->displayPartial('plugins::partials.latestPlugins');
    }

    public function search(): void {}
}
