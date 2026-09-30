<?php

declare(strict_types=1);

namespace Unit\app\Domain\Agent;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Domain\Agent\Services\WebSearchConfiguration;
use Leantime\Domain\Install\Services\SchemaBuilder;
use PDO;
use Unit\TestCase;

final class WebSearchConfigurationTest extends TestCase
{
    private SQLiteConnection $db;

    private mixed $oldEnvironmentKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnvironmentKey = $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] ?? null;
        unset($_ENV['JULIANNA_WEB_SEARCH_API_KEY']);
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        DB::swap($this->db);
        Schema::swap($this->db->getSchemaBuilder());
        Crypt::swap(new Encrypter(str_repeat('w', 32), 'AES-256-CBC'));
        (new SchemaBuilder(new AppSettings))->createAgentWebSearchSettingsTable();
    }

    protected function tearDown(): void
    {
        if ($this->oldEnvironmentKey === null) {
            unset($_ENV['JULIANNA_WEB_SEARCH_API_KEY']);
        } else {
            $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = $this->oldEnvironmentKey;
        }
        parent::tearDown();
    }

    public function test_website_key_is_encrypted_and_never_returned_in_public_state(): void
    {
        $settings = new WebSearchConfiguration;
        $settings->save('brave-test-secret', 7);

        $row = $this->db->table('julianna_agent_web_search_settings')->first();
        self::assertNotNull($row);
        self::assertNotSame('brave-test-secret', $row->encrypted_api_key);
        self::assertStringNotContainsString('brave-test-secret', $row->encrypted_api_key);
        self::assertSame('brave-test-secret', Crypt::decryptString($row->encrypted_api_key));
        self::assertSame('brave-test-secret', $settings->key());
        self::assertSame(['hasKey' => true, 'source' => 'website'], $settings->publicState());
        self::assertStringNotContainsString('brave-test-secret', json_encode($settings->publicState(), JSON_THROW_ON_ERROR));
    }

    public function test_disabling_website_key_masks_environment_fallback_without_affecting_ai_settings(): void
    {
        $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = 'env-brave-key';
        $settings = new WebSearchConfiguration;
        self::assertSame('env-brave-key', $settings->key());
        self::assertSame('environment', $settings->publicState()['source']);

        $settings->clear(7);
        self::assertSame('', $settings->key());
        self::assertSame(['hasKey' => false, 'source' => 'disabled'], $settings->publicState());
        self::assertNull($this->db->table('julianna_agent_web_search_settings')->value('encrypted_api_key'));
    }

    public function test_empty_and_control_character_keys_are_rejected(): void
    {
        $settings = new WebSearchConfiguration;
        foreach (['', "bad\nkey", str_repeat('x', 2049)] as $key) {
            try {
                $settings->save($key, 7);
                self::fail('Invalid search key accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->db->table('julianna_agent_web_search_settings')->count());
            }
        }
    }
}
