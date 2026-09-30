<?php

namespace Unit\app\Core\Configuration;

use Illuminate\Support\Facades\Blade;
use Leantime\Core\Configuration\AppSettings;
use Unit\TestCase;

class AssetVersionTest extends TestCase
{
    public function test_runtime_version_is_used_by_app_settings_and_login_assets(): void
    {
        $version = 'asset-version-regression';
        config(['version' => $version]);
        $this->app->forgetInstance(AppSettings::class);

        $actualVersion = $this->app->make(AppSettings::class)->appVersion;
        $this->assertSame($version, $actualVersion);

        // Render the production header's actual asset tags with the resolved
        // version. A hard-coded AppSettings default makes these URLs point to
        // files that are absent from a versioned Docker image.
        $header = file_get_contents(APP_ROOT.'/app/Views/Templates/sections/header.blade.php');
        $this->assertIsString($header);

        foreach (['css/main', 'css/app', 'js/compiled-app'] as $asset) {
            $tag = preg_match('~^<(?:link|script)[^\n]*?/dist/'.preg_quote($asset, '~').'\.[^\n]*$~m', $header, $matches);
            $this->assertSame(1, $tag, 'Expected versioned header tag for '.$asset);

            $rendered = Blade::render($matches[0], [
                'version' => $actualVersion,
                'cssBust' => 'test',
                'jsBust' => 'test',
            ]);
            $this->assertStringContainsString('/dist/'.$asset.'.'.$version.'.min.', $rendered);
        }
    }
}
