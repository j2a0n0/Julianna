<?php

namespace Leantime\Domain\Help\Controllers;

use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class About extends Controller
{
    private Environment $config;

    private AppSettings $appSettings;

    public function init(Environment $config, AppSettings $appSettings): void
    {
        $this->config = $config;
        $this->appSettings = $appSettings;
    }

    public function get(): Response
    {
        $this->tpl->assign(
            'version',
            trim((string) ($this->config->version ?? '')) ?: $this->appSettings->appVersion
        );
        $this->tpl->assign('commit', trim((string) ($this->config->commit ?? '')));
        $this->tpl->assign('sourceUrl', trim((string) ($this->config->sourceUrl ?? '')));

        return $this->tpl->display('help.about');
    }
}
