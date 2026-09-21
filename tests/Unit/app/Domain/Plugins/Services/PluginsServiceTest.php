<?php

namespace Unit\app\Domain\Plugins\Services;

use Leantime\Domain\Plugins\Services\Plugins as PluginService;
use Unit\TestCase;

/** Tests Julianna's local-only plugin service surface. */
class PluginsServiceTest extends TestCase
{
    use \Codeception\Test\Feature\Stub;

    public function test_remote_marketplace_helpers_are_absent(): void
    {
        foreach (['buildMarketplacePluginFromRequest', 'isBundle', 'parseMarketplaceError'] as $method) {
            $this->assertFalse(method_exists(PluginService::class, $method), $method.' must not expose marketplace behavior.');
        }
    }

    public function test_perform_plugin_action_returns_success_descriptor(): void
    {
        /** @var PluginService $service */
        $service = $this->make(PluginService::class, [
            'enablePlugin' => fn () => true,
        ]);

        $this->assertSame(
            ['notification.plugin_enable_success', 'success'],
            $service->performPluginAction('enable', 5),
        );
    }

    public function test_perform_plugin_action_returns_error_descriptor_on_failure(): void
    {
        /** @var PluginService $service */
        $service = $this->make(PluginService::class, [
            'disablePlugin' => fn () => false,
        ]);

        $this->assertSame(
            ['notification.plugin_disable_error', 'error'],
            $service->performPluginAction('disable', 5),
        );
    }

    public function test_perform_plugin_action_rejects_unknown_action(): void
    {
        /** @var PluginService $service */
        $service = $this->make(PluginService::class);

        $this->expectException(\InvalidArgumentException::class);

        $service->performPluginAction('explode', 5);
    }
}
