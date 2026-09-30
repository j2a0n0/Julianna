<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\AI\AiProvider;
use Leantime\Domain\IdeaRoom\AI\ProviderFactory;
use RuntimeException;

/** Installation-wide AI connector; credentials never enter sessions or responses. */
final class AiConfiguration
{
    private const TABLE = 'julianna_agent_ai_settings';

    private const PROVIDERS = ['openai', 'anthropic', 'deepseek', 'kimi'];

    /** @return array{provider:?string,model:?string,hasKey:bool,source:string} */
    public function publicState(): array
    {
        $row = $this->websiteRow();
        if ($row !== null) {
            return [
                'provider' => $row->provider,
                'model' => $row->model,
                'hasKey' => is_string($row->encrypted_api_key) && $row->encrypted_api_key !== '',
                'source' => $row->provider === null ? 'disabled' : 'website',
            ];
        }

        $provider = strtolower(trim(self::environmentValue('JULIANNA_AI_PROVIDER')));
        $model = trim(self::environmentValue('JULIANNA_AI_MODEL'));
        $key = self::environmentValue('JULIANNA_AI_API_KEY');

        return [
            'provider' => in_array($provider, self::PROVIDERS, true) ? $provider : null,
            'model' => $model !== '' ? $model : null,
            'hasKey' => trim($key) !== '',
            'source' => $provider !== '' || $model !== '' || $key !== '' ? 'environment' : 'none',
        ];
    }

    public function provider(): ?AiProvider
    {
        $row = $this->websiteRow();
        if ($row === null) {
            return ProviderFactory::fromEnvironment();
        }
        if (! is_string($row->provider) || ! is_string($row->model)
            || ! is_string($row->encrypted_api_key) || $row->encrypted_api_key === '') {
            return null;
        }

        return ProviderFactory::fromConfiguration(
            $row->provider,
            Crypt::decryptString($row->encrypted_api_key),
            $row->model,
        );
    }

    /** Returns a key only to server-side integrations, never to templates or responses. */
    public function apiKeyForProvider(string $provider): ?string
    {
        $row = $this->websiteRow();
        if ($row !== null) {
            return $row->provider === $provider && is_string($row->encrypted_api_key) && $row->encrypted_api_key !== ''
                ? Crypt::decryptString($row->encrypted_api_key)
                : null;
        }

        return strtolower(trim(self::environmentValue('JULIANNA_AI_PROVIDER'))) === $provider
            ? (self::environmentValue('JULIANNA_AI_API_KEY') ?: null)
            : null;
    }

    public function save(string $provider, string $model, ?string $newKey, int $actorUserId): void
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        if (! in_array($provider, self::PROVIDERS, true)
            || preg_match('/\A[A-Za-z0-9._:\/-]{1,128}\z/D', $model) !== 1
            || $actorUserId < 1) {
            throw new InvalidArgumentException('Choose a supported AI provider and model.');
        }
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('AI settings are unavailable until the database is updated.');
        }

        $newKey = $newKey === null ? '' : trim($newKey);
        if (strlen($newKey) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $newKey) === 1) {
            throw new InvalidArgumentException('Enter a valid API key.');
        }

        DB::transaction(function () use ($provider, $model, $newKey, $actorUserId): void {
            $existing = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $encryptedKey = $newKey !== ''
                ? Crypt::encryptString($newKey)
                : ($existing !== null && $existing->provider === $provider ? $existing->encrypted_api_key : null);

            if (! is_string($encryptedKey) || $encryptedKey === '') {
                throw new InvalidArgumentException('Enter an API key for this provider.');
            }
            if ($newKey === '' && Crypt::decryptString($encryptedKey) === '') {
                throw new InvalidArgumentException('Enter an API key for this provider.');
            }

            $now = now();
            DB::table(self::TABLE)->upsert([[
                'id' => 1,
                'provider' => $provider,
                'model' => $model,
                'encrypted_api_key' => $encryptedKey,
                'updated_by_user_id' => $actorUserId,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]], ['id'], ['provider', 'model', 'encrypted_api_key', 'updated_by_user_id', 'updated_at']);
        });
    }

    public function clear(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated user is required.');
        }
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('AI settings are unavailable until the database is updated.');
        }

        DB::transaction(static function () use ($actorUserId): void {
            $existing = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $now = now();
            DB::table(self::TABLE)->upsert([[
                'id' => 1,
                'provider' => null,
                'model' => null,
                'encrypted_api_key' => null,
                'updated_by_user_id' => $actorUserId,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]], ['id'], ['provider', 'model', 'encrypted_api_key', 'updated_by_user_id', 'updated_at']);
        });
    }

    private function websiteRow(): ?object
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)->where('id', 1)->first();
    }

    private static function environmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }
}
