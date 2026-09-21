<?php

namespace Leantime\Views\Composers;

use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\UI\Composer;

class Footer extends Composer
{
    public static array $views = [
        'global::sections.footer',
    ];

    protected AppSettings $settings;

    protected Environment $config;

    public function init(AppSettings $settings, Environment $config): void
    {
        $this->settings = $settings;
        $this->config = $config;
    }

    public function with(): array
    {
        return [
            'version' => trim((string) ($this->config->version ?? '')) ?: $this->settings->appVersion,
            'commit' => trim((string) ($this->config->commit ?? '')),
            'sourceUrl' => trim((string) ($this->config->sourceUrl ?? '')),
        ];
    }
}
