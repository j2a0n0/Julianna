<?php

namespace Leantime\Domain\Plugins\Hxcontrollers;

use Leantime\Core\Auth\Permissions\RequiresPermission;
use Leantime\Core\Controller\HtmxController;
use Leantime\Domain\Plugins\Permissions\PluginsPermissions;

class Details extends HtmxController
{
    protected static string $view = 'plugins::plugindetails';

    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function install(): string
    {
        throw new \RuntimeException('Remote plugin installation is not available in Julianna.');
    }
}
