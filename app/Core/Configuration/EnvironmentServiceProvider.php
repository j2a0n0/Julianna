<?php

namespace Leantime\Core\Configuration;

use Illuminate\Support\ServiceProvider;
use Leantime\Core\Events\DispatchesEvents;

class EnvironmentServiceProvider extends ServiceProvider
{
    use DispatchesEvents;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(AppSettings::class, function ($app): AppSettings {
            $settings = new AppSettings;
            $settings->appVersion = (string) $app['config']->get('version', $settings->appVersion);
            $settings->appCommit = (string) $app['config']->get('commit', $settings->appCommit);
            $settings->sourceUrl = (string) $app['config']->get('sourceUrl', $settings->sourceUrl);

            return $settings;
        });
    }

    public function boot()
    {
        self::dispatchEvent('config_initialized');
    }
}
