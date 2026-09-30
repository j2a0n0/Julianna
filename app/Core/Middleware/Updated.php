<?php

namespace Leantime\Core\Middleware;

use Closure;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Core\Events\DispatchesEvents;
use Leantime\Core\Http\IncomingRequest;
use Leantime\Domain\Setting\Repositories\Setting as SettingRepository;
use Symfony\Component\HttpFoundation\Response;

class Updated
{
    use DispatchesEvents;

    /**
     * Check if Leantime is installed
     *
     * @param  \Closure(IncomingRequest): Response  $next
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     **/
    public function handle(IncomingRequest $request, Closure $next): Response
    {
        $settingsDbVersion = app()->make(AppSettings::class)->dbVersion;
        $cachedDbVersion = session('dbVersion');

        // A deployment can change the required schema while a browser session
        // remains open. Only skip the settings query when that session already
        // knows it has the schema required by this version of the code.
        if (session('isUpdated')
            && ($request->isHtmxRequest() || $request->isApiOrCronRequest())
            && $cachedDbVersion !== null
            && $this->getVersionInt($cachedDbVersion) >= $this->getVersionInt($settingsDbVersion)
        ) {
            return $next($request);
        }

        $dbVersion = $cachedDbVersion ?? app()->make(SettingRepository::class)->getSetting('db-version');

        if ($dbVersion !== false) {
            // Setting dbVersion only if there is one in the db
            // Otherwise leave dbVersion unset so we can recheck every time the settings db returns false.
            session(['dbVersion' => $dbVersion]);
        }

        $dbVersionInt = $this->getVersionInt($dbVersion);
        $settingsDbVersionInt = $this->getVersionInt($settingsDbVersion);

        // Self-heal a stale session cache: the cached db-version survives an
        // update run by ANOTHER session (an admin upgrading the install), which
        // used to strand every other live session in a redirect loop (any page
        // -> /install/update -> back again) until their cookies were cleared.
        // Before concluding "not updated" from a CACHED value, re-read the real
        // version from the database — one extra query, and only on the path
        // that would otherwise redirect.
        if ($dbVersionInt < $settingsDbVersionInt && $cachedDbVersion !== null) {
            $freshDbVersion = app()->make(SettingRepository::class)->getSetting('db-version');
            if ($freshDbVersion !== false) {
                $dbVersion = $freshDbVersion;
                session(['dbVersion' => $dbVersion]);
                $dbVersionInt = $this->getVersionInt($dbVersion);
            }
        }

        session(['isUpdated' => $dbVersionInt >= $settingsDbVersionInt]);

        if (session('isUpdated')) {
            return $next($request);
        }

        if (! $response = $this->redirectToUpdate()) {
            return $next($request);
        }

        return $response;
    }

    /**
     * Redirect to update
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function redirectToUpdate(): Response|false
    {
        $frontController = app()->make(Frontcontroller::class);

        // The updater itself is owner-only in AuthCheck/Update. Authentication
        // must remain reachable while a previous image's schema is behind so
        // the owner can sign in and deliberately run the migration.
        $allowedRoutes = [
            'install', 'install.update', 'api.i18n',
            'auth.login', 'auth.mfa', 'auth.recovery',
            'auth.recoveryCodes', 'auth.resetPw',
        ];
        $allowedRoutes = self::dispatchFilter('allowedRoutes', $allowedRoutes);
        if (in_array($frontController::getCurrentRoute(), $allowedRoutes)) {
            return false;
        }

        $route = BASE_URL.'/install/update';
        $route = self::dispatchFilter('redirectroute', $route);

        return $frontController::redirect($route);
    }

    private function getVersionInt($version)
    {
        $versionArray = explode('.', $version);
        if (is_array($versionArray) && count($versionArray) == 3) {
            $major = $versionArray[0];
            $minor = str_pad($versionArray[1], 2, '0', STR_PAD_LEFT);
            $patch = str_pad($versionArray[2], 2, '0', STR_PAD_LEFT);
            $newDBVersion = $major.$minor.$patch;

            return $newDBVersion;
        }

        return false;
    }
}
