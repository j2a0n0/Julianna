<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Owner-managed, encrypted search credential; never exposes the plaintext to a view. */
final class WebSearchConfiguration
{
    private const TABLE = 'julianna_agent_web_search_settings';

    /** @return array{hasKey:bool,source:string} */
    public function publicState(): array
    {
        $row = $this->websiteRow();
        if ($row !== null) {
            return [
                'hasKey' => (bool) $row->enabled && is_string($row->encrypted_api_key) && $row->encrypted_api_key !== '',
                'source' => (bool) $row->enabled ? 'website' : 'disabled',
            ];
        }
        $key = self::environmentKey();

        return ['hasKey' => $key !== '', 'source' => $key !== '' ? 'environment' : 'none'];
    }

    public function key(): string
    {
        $row = $this->websiteRow();
        if ($row !== null) {
            if (! (bool) $row->enabled || ! is_string($row->encrypted_api_key) || $row->encrypted_api_key === '') {
                return '';
            }
            try {
                return Crypt::decryptString($row->encrypted_api_key);
            } catch (Throwable) {
                // A damaged ciphertext must not expose an environment fallback.
                return '';
            }
        }

        return self::environmentKey();
    }

    public function save(string $key, int $actorUserId): void
    {
        $key = trim($key);
        if ($actorUserId < 1 || $key === '' || strlen($key) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('Enter a valid search API key.');
        }
        $this->requireTable();
        DB::transaction(static function () use ($key, $actorUserId): void {
            $existing = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $now = now();
            DB::table(self::TABLE)->upsert([[
                'id' => 1,
                'enabled' => true,
                'encrypted_api_key' => Crypt::encryptString($key),
                'updated_by_user_id' => $actorUserId,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]], ['id'], ['enabled', 'encrypted_api_key', 'updated_by_user_id', 'updated_at']);
        });
    }

    public function clear(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated owner is required.');
        }
        $this->requireTable();
        DB::transaction(static function () use ($actorUserId): void {
            $existing = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $now = now();
            DB::table(self::TABLE)->upsert([[
                'id' => 1,
                'enabled' => false,
                'encrypted_api_key' => null,
                'updated_by_user_id' => $actorUserId,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]], ['id'], ['enabled', 'encrypted_api_key', 'updated_by_user_id', 'updated_at']);
        });
    }

    private function requireTable(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Web search settings are unavailable until the database is updated.');
        }
    }

    private function websiteRow(): ?object
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return null;
            }

            return DB::table(self::TABLE)->where('id', 1)->first();
        } catch (Throwable) {
            // Plain PHP catalog tests have no database. The installed app does.
            return null;
        }
    }

    private static function environmentKey(): string
    {
        $value = $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] ?? getenv('JULIANNA_WEB_SEARCH_API_KEY');

        return is_string($value) ? trim($value) : '';
    }
}
