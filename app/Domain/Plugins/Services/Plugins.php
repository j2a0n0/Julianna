<?php

namespace Leantime\Domain\Plugins\Services;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leantime\Core\Auth\Permissions\RequiresPermission;
use Leantime\Core\Configuration\Environment as EnvironmentCore;
use Leantime\Core\Events\DispatchesEvents;
use Leantime\Domain\Plugins\Models\InstalledPlugin;
use Leantime\Domain\Plugins\Models\MarketplacePlugin;
use Leantime\Domain\Plugins\Permissions\PluginsPermissions;
use Leantime\Domain\Plugins\Repositories\Plugins as PluginRepository;

/**
 * @api
 */
class Plugins
{
    use DispatchesEvents;

    private string $pluginDirectory = ROOT.'/../app/Plugins/';

    /**
     * Plugin types
     * custom: Plugin is loaded as a folder, available under discover plugins
     * system: Plugin is defined in config and loaded on start. Cannot delete, or disable plugin
     * marketplace: Plugin comes from maarketplace.
     */
    private array $pluginTypes = [
        'custom' => 'custom',
        'system' => 'system',
        'marketplace' => 'marketplace',
    ];

    /**
     * Plugin formats
     * phar: Phar plugins (only from marketplace)
     * folder: Folder plugins
     */
    private array $pluginFormat = [
        'phar' => 'phar',
        'folder' => 'phar',
    ];

    /**
     * @return void
     *
     * @throws BindingResolutionException
     **/
    public function __construct(
        private PluginRepository $pluginRepository,
        private EnvironmentCore $config,
    ) {}

    /**
     * Retrieves all plugins, optionally filtering only the enabled ones.
     *
     * @param  bool  $enabledOnly  If set to true, only enabled plugins will be returned.
     * @return false|array<InstalledPlugin> Returns an array of all plugins or false if an error occurs.
     *
     * @api
     */
    public function getAllPlugins(bool $enabledOnly = false): false|array
    {
        $installedPluginsById = [];

        try {
            $installedPlugins = $this->pluginRepository->getAllPlugins($enabledOnly);
        } catch (\Exception $e) {
            $installedPlugins = [];
        }

        // Build array with pluginId as $key
        foreach ($installedPlugins as &$plugin) {

            /** @var array<MarketplacePlugin> */
            $marketplacePluginCache = Cache::store('installation')->get('plugins.marketplacePluginsFlat', false);

            $plugin->type = $plugin->format === $this->pluginFormat['phar']
                ? $plugin->type = $this->pluginTypes['marketplace']
                : $plugin->type = $this->pluginTypes['custom'];

            // Make installed plugins pretty
            $pluginIdentifier = Str::replace('/', '_', Str::lower($plugin->name));
            if ($marketplacePluginCache && isset($marketplacePluginCache[$pluginIdentifier])) {
                $plugin->identifier = $marketplacePluginCache[$pluginIdentifier]->identifier;
                $plugin->name = $marketplacePluginCache[$pluginIdentifier]->name;
                $plugin->imageUrl = $marketplacePluginCache[$pluginIdentifier]->imageUrl;
                $plugin->description = $marketplacePluginCache[$pluginIdentifier]->excerpt;
                $plugin->vendorDisplayName = $marketplacePluginCache[$pluginIdentifier]->vendorDisplayName;
                $plugin->vendorId = $marketplacePluginCache[$pluginIdentifier]->vendorId;
                $plugin->vendorEmail = $marketplacePluginCache[$pluginIdentifier]->vendorEmail;
            }
            $installedPluginsById[$plugin->foldername] = $plugin;

        }

        // Gets plugins from the config, which are automatically enabled
        if (isset($this->config->plugins)) {
            $configplugins = explode(',', $this->config->plugins);
            collect($configplugins)
                ->filter(fn ($plugin) => ! empty($plugin))
                ->each(function ($plugin) use (&$installedPluginsById) {

                    try {
                        $pluginModel = $this->createPluginFromComposer($plugin);

                        $installedPluginsById[$plugin] ??= $pluginModel;
                        $installedPluginsById[$plugin]->enabled = true;
                        $installedPluginsById[$plugin]->type = $this->pluginTypes['system'];
                    } catch (Exception $e) {
                        report($e);
                    }
                });
        }

        /**
         * Filters array of plugins from database and config before returning
         *
         * @var array $allPlugins
         */
        $allPlugins = self::dispatch_filter('beforeReturnAllPlugins', $installedPluginsById, ['enabledOnly' => $enabledOnly]);

        return $allPlugins;
    }

    /**
     * @throws BindingResolutionException
     *
     * @api
     */
    public function isEnabled($pluginFolder): bool
    {
        $plugins = $this->getEnabledPlugins();

        foreach ($plugins as $plugin) {
            if (strtolower($plugin->foldername) == strtolower($pluginFolder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array|false|mixed
     *
     * @throws BindingResolutionException
     *
     * @api
     */
    public function getEnabledPlugins(): mixed
    {

        if (Cache::store('installation')->has('plugins.enabledPlugins') && $this->config->debug === false) {
            $enabledPlugins = static::dispatch_filter('beforeReturnCachedPlugins', Cache::store('installation')->get('plugins.enabledPlugins'), ['enabledOnly' => true]);

            return $enabledPlugins;
        }

        Cache::store('installation')->set('plugins.enabledPlugins', $this->getAllPlugins(enabledOnly: true));

        /**
         * Filters session array of enabled plugins before returning
         */
        return self::dispatch_filter(
            hook: 'beforeReturnCachedPlugins',
            payload: Cache::store('installation')->get('plugins.enabledPlugins'),
            available_params: ['enabledOnly' => true]);

    }

    /**
     * @return InstalledPlugin[]
     *
     * @throws BindingResolutionException
     *
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function discoverNewPlugins(): array
    {
        $this->clearCache();

        $installedPluginNames = array_map(fn ($plugin) => $plugin->foldername, $this->getAllPlugins());
        $scanned_directory = array_diff(scandir($this->pluginDirectory), ['..', '.']);

        $newPlugins = collect($scanned_directory)
            ->filter(fn ($directory) => is_dir("{$this->pluginDirectory}/{$directory}") && ! array_search($directory, $installedPluginNames))
            ->map(function ($directory) {
                try {
                    return $this->createPluginFromComposer($directory);
                } catch (\Exception $e) {
                    Log::warning("Can't create plugin from composer");
                    Log::warning($e);

                    return null;
                }
            })
            ->filter()->all();

        return $newPlugins;
    }

    public function createPluginFromComposer(string $pluginFolder, string $license_key = ''): InstalledPlugin
    {
        $pluginPath = Str::finish($this->pluginDirectory, DIRECTORY_SEPARATOR).Str::finish($pluginFolder, DIRECTORY_SEPARATOR);

        if (file_exists($composerPath = $pluginPath.'composer.json')) {
            $format = 'folder';
        } elseif (file_exists($composerPath = "phar://{$pluginPath}{$pluginFolder}.phar".DIRECTORY_SEPARATOR.'composer.json')) {
            $format = 'phar';
        } else {
            throw new \Exception(__('notifications.plugin_install_cant_find_composer'));
        }

        $json = file_get_contents($composerPath);
        $pluginFile = json_decode($json, true);

        $plugin = build(new InstalledPlugin)
            ->set('name', $pluginFile['name'])
            ->set('enabled', 0)
            ->set('description', $pluginFile['description'])
            ->set('version', $pluginFile['version'])
            ->set('installdate', date('y-m-d'))
            ->set('foldername', $pluginFolder)
            ->set('license', $license_key)
            ->set('format', $format)
            ->set('homepage', $pluginFile['homepage'])
            ->set('authors', json_encode($pluginFile['authors']))
            ->get();

        return $plugin;
    }

    /**
     * @throws BindingResolutionException
     *
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function installPlugin($pluginFolder): false|string
    {
        $this->clearCache();

        $pluginFolder = Str::studly($pluginFolder);

        try {
            $plugin = $this->createPluginFromComposer($pluginFolder);
        } catch (\Exception $e) {
            report($e);

            return false;
        }

        $pluginClassName = $this->getPluginClassName($plugin);
        $newPluginSvc = app()->make($pluginClassName);

        if (method_exists($newPluginSvc, 'install')) {
            try {
                $newPluginSvc->install();
            } catch (Exception $e) {
                report($e);

                return false;
            }
        }

        return $this->pluginRepository->addPlugin($plugin);
    }

    /**
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function enablePlugin(int $id): bool
    {
        $this->clearCache();

        $pluginModel = $this->pluginRepository->getPlugin($id);

        if ($pluginModel->format !== 'phar') {
            return $this->pluginRepository->enablePlugin($id);
        }

        if ($this->validLicense($pluginModel)) {
            return $this->pluginRepository->enablePlugin($id);
        }

        return false;

    }

    /**
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function disablePlugin(int $id): bool
    {
        $this->clearCache();

        $pluginModel = $this->pluginRepository->getPlugin($id);

        $result = $this->pluginRepository->disablePlugin($id);

        if ($pluginModel->format === 'phar') {

            $this->deactivate($pluginModel);

        }

        return $result;
    }

    /**
     * @throws BindingResolutionException
     *
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function removePlugin(int $id): bool
    {
        $this->clearCache();

        /** @var InstalledPlugin|false $plugin */
        $plugin = $this->pluginRepository->getPlugin($id);

        if (! $plugin) {
            return false;
        }

        try {
            // Any installation calls should happen right here.
            $pluginClassName = $this->getPluginClassName($plugin);
            $newPluginSvc = app()->make($pluginClassName);

            if (method_exists($newPluginSvc, 'uninstall')) {
                try {
                    $newPluginSvc->uninstall();
                } catch (\Exception $e) {
                    report($e);

                    return false;
                }
            }
        } catch (\Exception $e) {
            // Silence is golden
        }

        return $this->pluginRepository->removePlugin($id);

    }

    /**
     * @throws BindingResolutionException
     *
     * @api
     */
    public function getPluginClassName(InstalledPlugin $plugin): string
    {
        return app()->getNamespace()
            .'Plugins\\'
            .Str::studly($plugin->foldername)
            .'\\Services\\'
            .Str::studly($plugin->foldername);
    }

    /**
     * Julianna intentionally has no remote marketplace.
     *
     * @return array<never>
     */
    public function getMarketplacePlugins(int $page, string $query = ''): array
    {
        return [];
    }

    /**
     * Julianna does not query remote plugin update services.
     *
     * @return array<never>
     */
    public function getLatestPluginUpdates(int $page, string $query = ''): array
    {
        return [];
    }

    public function getMarketplacePlugin(string $identifier): false
    {
        return false;
    }

    /**
     * Remote plugin installation is permanently unavailable. Administrators
     * may install audited local folder plugins with the normal local commands.
     */
    public function installMarketplacePlugin(MarketplacePlugin $plugin, string $version): void
    {
        throw new \RuntimeException('Remote plugin installation is not available in Julianna.');
    }

    /**
     * Fresh Julianna installations do not support marketplace plugin types.
     */
    public function validLicense(InstalledPlugin $plugin): bool
    {
        return $plugin->getType() !== $this->pluginTypes['marketplace'];
    }

    /**
     * No remote deactivation is required for local plugins.
     */
    public function deactivate(InstalledPlugin $plugin): bool
    {
        return true;
    }

    /**
     * Runs a single plugin lifecycle action (install, enable, disable, remove) and
     * returns the notification descriptor describing the outcome.
     *
     * The returned array is a [messageKey, type] pair where messageKey is a language
     * key and type is the notification severity ('success' or 'error'). This keeps the
     * dynamic dispatch and notification-key assembly out of the controller.
     *
     * @param  string  $action  One of: install, enable, disable, remove.
     * @param  mixed  $id  The plugin identifier (folder name for install, numeric id otherwise).
     * @return array{0: string, 1: string} A [messageKey, type] notification descriptor.
     *
     * @throws \InvalidArgumentException If the action is not a supported lifecycle action.
     * @throws BindingResolutionException
     *
     * @api
     */
    #[RequiresPermission(PluginsPermissions::MANAGE, global: true)]
    public function performPluginAction(string $action, mixed $id): array
    {
        $allowedActions = ['install', 'enable', 'disable', 'remove'];

        if (! in_array($action, $allowedActions, true)) {
            throw new \InvalidArgumentException("Unsupported plugin action: {$action}");
        }

        $succeeded = $this->{"{$action}Plugin"}($id);

        return $succeeded
            ? ["notification.plugin_{$action}_success", 'success']
            : ["notification.plugin_{$action}_error", 'error'];
    }

    /**
     * Aggregates the CSS contributed by enabled plugins into a single payload.
     *
     * Runs the 'pluginCss' filter to collect the list of plugin CSS files, keeps only
     * the files that actually exist under APP_ROOT/plugins, reads their contents and
     * concatenates them into one string.
     *
     * @return string The combined CSS contents of all registered, existing plugin files.
     *
     * @api
     */
    public function getAggregatedPluginCss(): string
    {
        $cssFiles = self::dispatch_filter('pluginCss', []);

        $cssStrs = collect($cssFiles)
            ->filter(fn ($file) => file_exists(APP_ROOT."/plugins/$file"))
            ->map(fn ($file) => file_get_contents(APP_ROOT."/plugins/$file"))
            ->all();

        return implode('', $cssStrs);
    }

    /**
     * Clears cached data related to installation, sessions, and predefined file paths.
     *
     * Removes cached domain events, commands, and enabled plugins from the installation cache store.
     * Clears specific session variables related to template paths and composers.
     * Deletes stored cached files for view paths and composer paths in the framework's storage.
     *
     * @return void
     */
    public function clearCache()
    {
        Cache::store('installation')->forget('domainEvents');
        Cache::store('installation')->forget('commands');
        Cache::store('installation')->forget('plugins.enabledPlugins');
        // Permission engine caches (provider discovery + the role->permission map/meta), all
        // cross-request on the installation store — a documented cache clear must bust them too,
        // or a newly-shipped domain/plugin permission class stays invisible (and acts as a
        // recovery path if the provider cache ever goes stale).
        Cache::store('installation')->forget('permissionProviders');
        Cache::store('installation')->forget('leantime.permissionMap');
        Cache::store('installation')->forget('leantime.permissionMeta');

        session()->forget('template_paths');
        session()->forget('composers');

        $files = app()->make(\Illuminate\Filesystem\Filesystem::class);
        $viewPathCachePath = storage_path('framework/viewPaths.php');
        $files->delete($viewPathCachePath);

        $composerPathCachePath = storage_path('framework/composerPaths.php');
        $files->delete($composerPathCachePath);
    }
}
