<?php

namespace Leantime\Domain\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Leantime\Core\Configuration\Environment as EnvironmentCore;
use Leantime\Core\Controller\Frontcontroller as FrontcontrollerCore;
use Leantime\Core\Events\DispatchesEvents;
use Leantime\Core\Language as LanguageCore;
use Leantime\Core\UI\Theme;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Repositories\AccessTokenRepository;
use Leantime\Domain\Auth\Repositories\Auth as AuthRepository;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\Setting\Repositories\Setting as SettingRepository;
use Leantime\Domain\Users\Repositories\Users as UserRepository;
use Ramsey\Uuid\Uuid;
use RobThree\Auth\TwoFactorAuth;

class Auth implements Authenticatable
{
    use DispatchesEvents, HasApiTokens, \Illuminate\Auth\Authenticatable;

    /**
     * @var int|null user id from DB
     */
    private ?int $userId = null;

    private ?string $password = null;

    private ?SessionManager $session = null;

    /**
     * @var string userrole (admin, client, employee)
     */
    public string $role = '';

    public array $settings = [];

    /**
     * @var int time for cookie
     */
    public mixed $cookieTime;

    public string $error = '';

    public string $success = '';

    public string|bool $resetInProgress = false;

    /**
     * How often can a user reset a password before it has to be changed
     */
    public int $pwResetLimit = 5;

    private EnvironmentCore $config;

    public LanguageCore $language;

    public SettingRepository $settingsRepo;

    public AuthRepository $authRepo;

    public UserRepository $userRepo;

    private AccessTokenRepository $tokenRepo;

    /**
     * __construct - getInstance of session and get sessionId and refers to login if post is set
     *
     * @throws BindingResolutionException
     */
    public function __construct(
        EnvironmentCore $config,
        ?SessionManager $session,
        LanguageCore $language,
        SettingRepository $settingsRepo,
        AuthRepository $authRepo,
        UserRepository $userRepo,
        AccessTokenRepository $tokenRepo
    ) {
        $this->config = $config;
        $this->session = $session;
        $this->language = $language;
        $this->settingsRepo = $settingsRepo;
        $this->authRepo = $authRepo;
        $this->userRepo = $userRepo;
        $this->tokenRepo = $tokenRepo;

        $this->cookieTime = $this->config->sessionExpiration;
    }

    /**
     * @return string|bool returns role as string or false on failure
     *
     * @throws BindingResolutionException
     */
    public static function getRoleToCheck(bool $forceGlobalRoleCheck): string|bool
    {
        if (session()->exists('userdata') === false) {
            return false;
        }

        if ($forceGlobalRoleCheck) {
            $roleToCheck = session('userdata.role');
            // If projectRole is not defined or if it is set to inherited
        } elseif (! session()->exists('userdata.projectRole') || session('userdata.projectRole') == 'inherited' || session('userdata.projectRole') == '') {
            $roleToCheck = session('userdata.role');
            // Do not overwrite admin or owner roles
        } elseif (session('userdata.role') == Roles::$owner || session('userdata.role') == Roles::$admin || session('userdata.role') == Roles::$manager) {
            $roleToCheck = session('userdata.role');
            // In all other cases check the project role
        } else {
            $roleToCheck = session('userdata.projectRole');
        }

        // Ensure the role is a valid role. An unresolvable role here makes the permission engine
        // deny EVERYTHING (every #[RequiresPermission] check fails) — so log it loudly with
        // context. This exact breadcrumb ("invalid role detected: 50") is what surfaced the 3.9.x
        // Bearer regression where a session stored the raw role int instead of its name string.
        if (in_array($roleToCheck, Roles::getRoles()) === false) {

            Log::warning('Invalid role in session — authorization will deny everything. Resolved role: '.var_export($roleToCheck, true).' (user '.(session('userdata.id') ?? 'guest').'). Expected one of: '.implode(', ', Roles::getRoles()));

            return false;
        }

        return $roleToCheck;
    }

    /**
     * login - Validate POST-data with DB
     *
     *
     *
     * @throws BindingResolutionException
     */
    public function login(string $username, string $password): bool
    {
        // Credentials live exclusively in julianna_auth_accounts. Web login
        // must pass through the dedicated password and MFA controllers.
        return false;
    }

    /**
     * Create a new personal access token
     */
    public function createToken(string $name, array $abilities = ['*']): array
    {
        if (! SecureAuthRequest::hasFullWebAuthentication()) {
            throw new \Exception('A fully authenticated Julianna browser session is required to create a token.');
        }

        return $this->tokenRepo->createToken($this->getUserId(), $name, $abilities);
    }

    /**
     * @return false|void
     *
     * @throws BindingResolutionException
     */
    public function setUserSession(mixed $user, bool $isExternalAuth = false)
    {
        if (! $user || ! is_array($user)) {
            return false;
        }

        // Web-login session. twoFAVerified: false — the web flow enforces interactive 2FA via the
        // AuthCheck gate. Built via the shared factory (role NAME string + consistent fields), with
        // the web-only globalUserId added on top.
        $currentUser = UserSessionBuilder::build($user, isExternalAuth: $isExternalAuth, twoFAVerified: false);
        $currentUser['globalUserId'] = Uuid::uuid5(Uuid::NAMESPACE_DNS, strtolower($user['username']));

        $currentUser = self::dispatch_filter('user_session_vars', $currentUser);

        session(['userdata' => $currentUser]);
        session(['usersettings' => $currentUser['settings']]);

        $this->updateUserSessionDB($currentUser['id'], session()->getId());

        // Clear user theme cache on login
        Theme::clearCache();
    }

    public function updateUserSessionDB(int $userId, string $sessionID): bool
    {
        return $this->authRepo->updateUserSession($userId, $sessionID, (string) time());
    }

    /**
     * logged_in - Check if logged in and Update sessions
     */
    public function loggedIn(): bool
    {
        // Check if we actually have a php session available
        if (session()->exists('userdata')) {
            return true;
            // If the session doesn't have any session data we are out of sync. Start again
        } else {
            return false;
        }
    }

    /**
     * Checks if a user is logged in.
     *
     * @return bool Returns true if the user is logged in, false otherwise.
     */
    public static function isLoggedIn(): bool
    {

        // Check if we actually have a php session available
        if (session()->exists('userdata')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * logout - destroy sessions and cookies
     *
     *
     * @throws BindingResolutionException
     */
    public function logout(): void
    {

        $this->authRepo->invalidateSession($this->session->getId());

        $sessionsToDestroy = self::dispatch_filter('sessions_vars_to_destroy', [
            'userdata',
            'template',
            'subdomainData',
            'currentProject',
            'currentSprint',
            'projectsettings',
            'currentSubscriptions',
            'lastTicketView',
            'lastFilteredTicketTableView',
        ]);

        foreach ($sessionsToDestroy as $key) {
            session()->forget($key);
        }

        self::dispatch_event('afterSessionDestroy', ['authService' => app()->make(self::class)]);

    }

    /** Legacy credential and invitation endpoints fail closed in Julianna. */
    public function validateResetLink(string $hash): bool
    {
        return false;
    }

    public function getUserByInviteLink(string $hash): false
    {
        return false;
    }

    public function generateLinkAndSendEmail(string $username): bool
    {
        return false;
    }

    public function changePw(string $password, string $hash): bool
    {
        return false;
    }

    public function checkPasswordStrength(string $password): bool
    {
        return mb_strlen($password, '8bit') >= 12 && mb_strlen($password, '8bit') <= 128;
    }

    /**
     * @api
     */
    public function resetPassword(string $password, string $passwordConfirm, string $hash): string
    {
        return 'error';
    }

    /**
     * resolveSafeRedirect - resolves a user supplied redirect target into a safe,
     * application-internal absolute URL, guarding against open redirects.
     *
     * @param  string|null  $redirect  the raw redirect target (typically from the request)
     * @return string an absolute URL that is safe to redirect to
     *
     * @api
     */
    public function resolveSafeRedirect(?string $redirect): string
    {
        $redirectUrl = BASE_URL.'/dashboard/home';

        if ($redirect !== null && trim($redirect) !== '' && trim($redirect) !== '/') {
            // Normalize backslash-based protocol tricks (e.g. \/\/attacker.com)
            // to forward slashes before any checks.
            $url = str_replace('\\', '/', rawurldecode($redirect));

            // Drop control characters and surrounding whitespace before any guard, so a
            // padded variant (" //evil.com", "%09//evil.com") can't slip past the checks
            // below and can't reach the Location header.
            $url = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $url));

            // Strip the application base URL when present so that same-origin
            // absolute URLs (e.g. https://julianna.example/dashboard/home) are
            // treated the same as their relative counterparts.
            //
            // Match only on a real boundary: a bare str_starts_with() would also fire on
            // https://hostile.com/pwn when BASE_URL is https://host, rewriting an external
            // URL into the bogus internal path /ile.com/pwn instead of rejecting it. The
            // same applies to subdirectory installs (BASE_URL /app vs a /application path).
            $base = rtrim(BASE_URL, '/');

            if ($base !== '' && (
                $url === $base
                || str_starts_with($url, $base.'/')
                || str_starts_with($url, $base.'?')
                || str_starts_with($url, $base.'#')
            )) {
                $url = substr($url, strlen($base));
            }

            // Guard: protocol-relative URL (//attacker.com) — explicitly reject.
            // FILTER_VALIDATE_URL treats these as valid without a scheme, but
            // browsers resolve them to the current scheme, making them an open
            // redirect vector.
            if (str_starts_with($url, '//')) {
                return $redirectUrl;
            }

            // Guard: external absolute URL — reject.
            // filter_var returns the URL (truthy) for well-formed absolute URLs
            // with a scheme; relative paths return false.
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                return $redirectUrl;
            }

            // At this point $url is a relative path. Guard against an empty
            // path that could result from stripping a BASE_URL-only input.
            $url = ltrim($url, '/');

            // Block redirect to logout — allowing a POST-login redirect to
            // /auth/logout would create a forced-logout loop. Compare the normalized
            // path so the query string, a trailing slash and casing can't be used to
            // walk around the block (/auth/logout/, /auth/logout?next=/x, /AUTH/logout).
            $path = rtrim(strtolower(strtok($url, '?#')), '/');

            if ($url !== '' && $path !== 'auth/logout') {
                $redirectUrl = BASE_URL.'/'.$url;
            }
        }

        return $redirectUrl;
    }

    /**
     * Resolve the post-MFA destination for the now-authenticated user.
     *
     * The global dashboard is manager-only. Public signups may legitimately
     * be approved as read-only, commenter, or editor users, so sending those
     * accounts to the default dashboard would turn a successful first login
     * into a 403. Explicit non-dashboard destinations remain untouched.
     */
    public function resolveAuthenticatedRedirect(?string $redirect): string
    {
        $resolved = $this->resolveSafeRedirect($redirect);
        $role = session('userdata.role');

        if ($resolved === BASE_URL.'/dashboard/home'
            && in_array($role, [Roles::$readonly, Roles::$commenter, Roles::$editor], true)
        ) {
            return BASE_URL.'/users/editOwn';
        }

        return $resolved;
    }

    /**
     * shouldHideLoginForm - determines whether the default login form should be hidden,
     * combining the admin setting with the configured disableLoginForm flag.
     *
     * @return bool returns true if the default login form should be hidden
     *
     * @api
     */
    public function shouldHideLoginForm(): bool
    {
        $hideLogin = $this->settingsRepo->getSetting('auth.hideDefaultLogin');

        if (! empty($hideLogin) && $hideLogin == 'on') {
            return true;
        }

        return (bool) $this->config->disableLoginForm;
    }

    /**
     * getLoginInputPlaceholder - returns the translation key for the login input placeholder
     * depending on whether LDAP authentication is enabled.
     *
     * @return string the placeholder translation key
     *
     * @api
     */
    public function getLoginInputPlaceholder(): string
    {
        return 'input.placeholders.enter_email';
    }

    /**
     * @throws BindingResolutionException
     */
    public static function userIsAtLeast(string $role, bool $forceGlobalRoleCheck = false): bool
    {

        // Force Global Role check to circumvent projectRole checks for global controllers (users, projects, clients etc)
        $roleToCheck = self::getRoleToCheck($forceGlobalRoleCheck);

        if ($roleToCheck === false) {
            return false;
        }

        $testKey = array_search($role, Roles::getRoles());

        if ($role == '' || $testKey === false) {
            Log::warning('Check for invalid role detected: '.$role);

            return false;
        }

        $currentUserKey = array_search($roleToCheck, Roles::getRoles());

        if ($testKey <= $currentUserKey) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @throws HttpResponseException
     */
    public static function authOrRedirect(array|string $role, bool $forceGlobalRoleCheck = false): bool
    {
        if (self::userHasRole($role, $forceGlobalRoleCheck)) {
            return true;
        }

        throw new HttpResponseException(FrontcontrollerCore::redirect(BASE_URL.'/errors/error403'));
    }

    /**
     * @throws BindingResolutionException
     */
    public static function userHasRole(string|array $role, bool $forceGlobalRoleCheck = false): bool
    {

        // Force Global Role check to circumvent projectRole checks for global controllers (users, projects, clients etc)
        $roleToCheck = self::getRoleToCheck($forceGlobalRoleCheck);

        if (is_array($role) && in_array($roleToCheck, $role)) {
            return true;
        } elseif ($role == $roleToCheck) {
            return true;
        }

        return false;
    }

    public static function getRole(): void {}

    public static function getUserClientId(): mixed
    {
        return session('userdata.clientId');
    }

    public static function getUserId(): mixed
    {
        return session('userdata.id');
    }

    public function use2FA(): mixed
    {
        return session('userdata.twoFAEnabled');
    }

    public function verify2FA(string $code): bool
    {
        $twoFactorAuthentication = new TwoFactorAuth('Julianna');

        return $twoFactorAuthentication->verifyCode(session('userdata.twoFASecret'), $code);
    }

    public function get2FAVerified(): mixed
    {
        return session('userdata.twoFAVerified');
    }

    public function set2FAVerified(): void
    {
        session(['userdata.twoFAVerified' => true]);
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->userId;
    }

    public function getAuthPassword()
    {
        return $this->password;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return ''; // Not implemented yet (Authenticatable::getRememberToken is contractually a string)
    }

    public function setRememberToken($value)
    {
        // Not implemented yet
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    public function getUserById($id)
    {
        return (object) $this->userRepo->getUser($id);
    }

    public function validateToken(string $token): bool
    {
        $user = $this->getUserByToken($token);

        if ($user) {
            $this->setUserSession($user);

            // Turn off 2FA for token verification
            $this->set2FAVerified();

            return true;
        }

        return false;

    }

    public function getUserByToken(string $token): array|bool
    {
        $tokenModel = $this->tokenRepo->findToken($token);

        if (! $tokenModel) {
            return false;
        }

        if ($tokenModel['expires_at'] && strtotime($tokenModel['expires_at']) < time()) {
            return false;
        }

        // Load the user associated with this token
        $user = $this->userRepo->getUser($tokenModel['tokenable_id']);
        if (! $user || strtolower((string) ($user['status'] ?? '')) !== 'a') {
            return false;
        }

        $account = app(AccountRepository::class)->findByUserId((int) $user['id']);
        if ($account !== null && $account->state !== AccountState::ACTIVE) {
            return false;
        }

        $this->tokenRepo->updateLastUsedAt($tokenModel['id']);

        return $user;

    }
}
