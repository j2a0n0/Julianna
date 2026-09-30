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
use Leantime\Domain\Agent\Services\AiConfiguration;
use Leantime\Domain\IdeaRoom\AI\OpenAiProvider;
use Leantime\Domain\Install\Services\SchemaBuilder;
use PDO;
use Unit\TestCase;

final class AiConfigurationTest extends TestCase
{
    private SQLiteConnection $db;

    /** @var array<string, mixed> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['JULIANNA_AI_PROVIDER', 'JULIANNA_AI_MODEL', 'JULIANNA_AI_API_KEY'] as $name) {
            $this->previousEnvironment[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }

        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        DB::swap($this->db);
        Schema::swap($this->db->getSchemaBuilder());
        Crypt::swap(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        parent::tearDown();
    }

    public function test_website_key_is_encrypted_at_rest_and_absent_from_public_state(): void
    {
        $this->createTable();
        $settings = new AiConfiguration;

        $settings->save('deepseek', 'deepseek-chat', 'secret-value-for-test', 7);

        $stored = $this->db->table('julianna_agent_ai_settings')->first();
        self::assertNotNull($stored);
        self::assertNotSame('secret-value-for-test', $stored->encrypted_api_key);
        self::assertStringNotContainsString('secret-value-for-test', $stored->encrypted_api_key);
        self::assertSame('secret-value-for-test', Crypt::decryptString($stored->encrypted_api_key));
        self::assertSame(7, $stored->updated_by_user_id);
        self::assertSame([
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'hasKey' => true,
            'source' => 'website',
        ], $settings->publicState());
        self::assertStringNotContainsString('secret-value-for-test', json_encode($settings->publicState(), JSON_THROW_ON_ERROR));
        self::assertInstanceOf(OpenAiProvider::class, $settings->provider());
    }

    public function test_blank_key_reuses_only_same_provider_credential(): void
    {
        $this->createTable();
        $settings = new AiConfiguration;
        $settings->save('deepseek', 'deepseek-chat', 'original-key', 7);
        $originalCiphertext = $this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key');

        $settings->save('deepseek', 'deepseek-reasoner', null, 7);
        self::assertSame($originalCiphertext, $this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key'));
        self::assertSame('deepseek-reasoner', $settings->publicState()['model']);

        try {
            $settings->save('kimi', 'kimi-model', null, 7);
            self::fail('Changing providers without a new key should fail.');
        } catch (InvalidArgumentException) {
            self::assertSame('deepseek', $settings->publicState()['provider']);
        }

        $settings->save('kimi', 'kimi-model', 'new-provider-key', 7);
        self::assertSame('kimi', $settings->publicState()['provider']);
        self::assertSame('new-provider-key', Crypt::decryptString(
            $this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key'),
        ));
    }

    public function test_disabled_website_setting_masks_environment_fallback(): void
    {
        $_ENV['JULIANNA_AI_PROVIDER'] = 'deepseek';
        $_ENV['JULIANNA_AI_MODEL'] = 'deepseek-chat';
        $_ENV['JULIANNA_AI_API_KEY'] = 'environment-secret';
        $settings = new AiConfiguration;

        self::assertSame('environment', $settings->publicState()['source']);
        self::assertInstanceOf(OpenAiProvider::class, $settings->provider());

        $this->createTable();
        $settings->clear(7);
        self::assertSame([
            'provider' => null,
            'model' => null,
            'hasKey' => false,
            'source' => 'disabled',
        ], $settings->publicState());
        self::assertNull($settings->provider());
        self::assertNull($this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key'));
    }

    public function test_invalid_provider_model_and_missing_key_are_rejected(): void
    {
        $this->createTable();
        $settings = new AiConfiguration;

        foreach ([
            ['unknown', 'model', 'key'],
            ['deepseek', 'bad model with spaces', 'key'],
            ['deepseek', 'deepseek-chat', null],
        ] as [$provider, $model, $key]) {
            try {
                $settings->save($provider, $model, $key, 7);
                self::fail('Invalid AI settings should be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->db->table('julianna_agent_ai_settings')->count());
            }
        }
    }

    private function createTable(): void
    {
        (new SchemaBuilder(new AppSettings))->createAgentAiSettingsTable();
    }
}
