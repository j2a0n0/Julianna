<?php

namespace Leantime\Domain\Auth\Services;

use Leantime\Core\Configuration\Environment;
use Leantime\Domain\Auth\Support\SecureAuthRequest;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Services\JuliannaAuth;
use Leantime\Domain\Users\Repositories\Users as UserRepository;
use RuntimeException;
use Throwable;

/**
 * Creates the legacy userdata session only after Julianna password and MFA
 * authentication have both succeeded.
 */
final class JuliannaSessionBridge
{
    public function __construct(
        private readonly Auth $legacyAuth,
        private readonly UserRepository $users,
        private readonly JuliannaAuth $auth,
        private readonly Environment $config,
    ) {}

    public function establish(Account $account): void
    {
        if (! $account->canAuthenticate() || $account->mfaConfirmedAt === null || $account->userId === null) {
            throw new RuntimeException('A fully authenticated Julianna account is required.');
        }

        $user = $this->users->getUser($account->userId);
        if (! is_array($user) || strtolower((string) ($user['status'] ?? '')) !== 'a') {
            throw new RuntimeException('The linked application user is unavailable.');
        }

        // Rotate again at the MFA trust boundary, then register only the new
        // identifier in the server-side Julianna session registry.
        SecureAuthRequest::rotateSession();
        try {
            $this->legacyAuth->setUserSession($user);
            if (! session()->exists('userdata')) {
                throw new RuntimeException('Unable to construct the application session.');
            }

            session()->put('userdata.twoFAEnabled', true);
            session()->put('userdata.twoFAVerified', true);
            session()->put('userdata.twoFASecret', '');

            $version = $this->auth->startSession(
                $account->id,
                session()->getId(),
                max(1, (int) ($this->config->sessionExpiration ?? 480)),
            );

            SecureAuthRequest::clearPendingAuthentication();
            session()->put('julianna_auth.authenticated_account_id', $account->id);
            session()->put('julianna_auth.session_version', $version);
        } catch (Throwable $failure) {
            // Never leave a legacy userdata session behind when the Julianna
            // registry write fails. Otherwise the next request could appear
            // authenticated without having a revocable MFA-backed session.
            try {
                $this->auth->revokeSession(session()->getId());
            } catch (Throwable) {
                // Preserve the original failure; local session invalidation
                // below is still sufficient to fail closed in this browser.
            }

            try {
                $this->legacyAuth->logout();
            } catch (Throwable) {
                // Database cleanup is best effort after a registry failure.
            }

            SecureAuthRequest::clearPendingAuthentication();
            session()->forget([
                'userdata',
                'julianna_auth.authenticated_account_id',
                'julianna_auth.session_version',
            ]);
            session()->invalidate();
            session()->regenerateToken();

            throw $failure;
        }
    }
}
