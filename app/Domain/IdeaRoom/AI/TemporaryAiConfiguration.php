<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

/** Local-only, per-authenticated-session provider override for hands-on testing. */
final class TemporaryAiConfiguration
{
    private const SESSION_KEY = 'julianna.idea_room.temporary_ai';

    private const LIFETIME_SECONDS = 3600;

    public static function enabled(?Request $request = null): bool
    {
        $request ??= request();
        $environment = strtolower((string) config('app.env', 'production'));
        $flag = $_ENV['JULIANNA_AI_TEST_CONNECTOR_ENABLED'] ?? getenv('JULIANNA_AI_TEST_CONNECTOR_ENABLED');

        return in_array($environment, ['local', 'dev', 'development', 'testing'], true)
            && in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true)
            && config('session.driver') !== 'cookie'
            && ($request->isSecure() || in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true));
    }

    /** @return array{provider: string, model: string, apiKey: string}|null */
    public static function current(?Request $request = null): ?array
    {
        $request ??= request();
        if (! self::enabled($request) || ! self::authenticated()) {
            return null;
        }

        $stored = session()->get(self::SESSION_KEY);
        if (! is_array($stored)) {
            return null;
        }
        if ((int) ($stored['userId'] ?? 0) !== (int) session('userdata.id')
            || (int) ($stored['accountId'] ?? 0) !== (int) session('julianna_auth.authenticated_account_id')
            || (int) ($stored['expiresAt'] ?? 0) <= time()) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        try {
            $apiKey = Crypt::decryptString((string) ($stored['encryptedKey'] ?? ''));
        } catch (Throwable) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['provider' => (string) $stored['provider'], 'model' => (string) $stored['model'], 'apiKey' => $apiKey];
    }

    /** @return array{provider: string, model: string, expiresAt: int} */
    public static function details(?Request $request = null): ?array
    {
        $request ??= request();
        if (self::current($request) === null) {
            return null;
        }
        $stored = session()->get(self::SESSION_KEY);

        return ['provider' => $stored['provider'], 'model' => $stored['model'], 'expiresAt' => $stored['expiresAt']];
    }

    /** @return array{provider: string, model: string, expiresAt: int} */
    public static function save(Request $request, string $provider, string $model, string $apiKey): array
    {
        self::requireAccess($request);
        $provider = strtolower(trim($provider));
        $model = trim($model);
        $apiKey = trim($apiKey);
        if (! in_array($provider, ['openai', 'anthropic', 'deepseek', 'kimi'], true)
            || preg_match('/\A[a-zA-Z0-9._:\/-]{1,100}\z/D', $model) !== 1
            || $apiKey === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x1f\x7f]/', $apiKey) === 1) {
            throw new InvalidArgumentException('Choose a supported provider and model, and enter a valid API key.');
        }

        $expiresAt = time() + self::LIFETIME_SECONDS;
        session()->put(self::SESSION_KEY, [
            'userId' => (int) session('userdata.id'),
            'accountId' => (int) session('julianna_auth.authenticated_account_id'),
            'provider' => $provider,
            'model' => $model,
            'encryptedKey' => Crypt::encryptString($apiKey),
            'expiresAt' => $expiresAt,
        ]);

        return ['provider' => $provider, 'model' => $model, 'expiresAt' => $expiresAt];
    }

    public static function clear(Request $request): void
    {
        self::requireAccess($request);
        session()->forget(self::SESSION_KEY);
    }

    private static function authenticated(): bool
    {
        return (int) session('userdata.id') > 0
            && (int) session('julianna_auth.authenticated_account_id') > 0
            && (bool) session('userdata.twoFAVerified');
    }

    private static function requireAccess(Request $request): void
    {
        if (! self::enabled($request) || ! self::authenticated()) {
            throw new InvalidArgumentException('Temporary AI configuration is unavailable.');
        }
    }
}
