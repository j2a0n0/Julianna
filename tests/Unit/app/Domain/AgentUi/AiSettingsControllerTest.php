<?php

declare(strict_types=1);

namespace Unit\app\Domain\AgentUi;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\UI\Template;
use Leantime\Domain\Agent\Services\AiConfiguration;
use Leantime\Domain\Agent\Services\ProviderModelCatalog;
use Leantime\Domain\AgentUi\Controllers\AiSettingsController;
use Leantime\Domain\Install\Services\SchemaBuilder;
use PDO;
use Symfony\Component\HttpFoundation\Response;
use Unit\TestCase;

final class AiSettingsControllerTest extends TestCase
{
    private SQLiteConnection $db;

    protected function setUp(): void
    {
        parent::setUp();
        session()->forget([
            'userdata',
            'julianna_auth.authenticated_account_id',
            'julianna_auth.session_version',
            'julianna_auth.account_id',
            'agent_ai_settings_status',
            'agent_web_search_status',
        ]);

        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        DB::swap($this->db);
        Schema::swap($this->db->getSchemaBuilder());
        Crypt::swap(new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
        (new SchemaBuilder(new AppSettings))->createAgentAiSettingsTable();
        (new SchemaBuilder(new AppSettings))->createAgentWebSearchSettingsTable();
    }

    protected function tearDown(): void
    {
        session()->forget([
            'userdata',
            'julianna_auth.authenticated_account_id',
            'julianna_auth.session_version',
            'julianna_auth.account_id',
            'agent_ai_settings_status',
            'agent_web_search_status',
        ]);
        parent::tearDown();
    }

    public function test_guest_and_token_like_owner_cannot_reach_any_settings_action(): void
    {
        $template = $this->createMock(Template::class);
        $template->expects(self::never())->method('display');
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(self::never())->method('hit');
        $controller = new AiSettingsController($template, new AiConfiguration, $rateLimiter);

        foreach ([false, true] as $tokenLikeOwner) {
            if ($tokenLikeOwner) {
                // A request-scoped API principal may have owner userdata, but
                // lacks the MFA-backed browser-session registry markers.
                session()->put('userdata', ['id' => 7, 'role' => 'owner']);
            }
            foreach (['show', 'save', 'test', 'clear', 'saveWebSearch', 'clearWebSearch'] as $action) {
                try {
                    in_array($action, ['save', 'test', 'saveWebSearch'], true)
                        ? $controller->{$action}($this->request())
                        : $controller->{$action}();
                    self::fail($action.' must reject this principal.');
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_mfa_backed_non_owner_cannot_view_or_modify_settings(): void
    {
        $this->authenticate('admin');
        $template = $this->createMock(Template::class);
        $template->expects(self::never())->method('display');
        $controller = new AiSettingsController($template, new AiConfiguration, $this->createMock(RateLimiter::class));

        foreach (['show', 'save', 'test', 'clear', 'saveWebSearch', 'clearWebSearch'] as $action) {
            try {
                in_array($action, ['save', 'test', 'saveWebSearch'], true)
                    ? $controller->{$action}($this->request())
                    : $controller->{$action}();
                self::fail($action.' must be owner-only.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_owner_get_and_save_responses_never_contain_the_pasted_key(): void
    {
        $this->authenticate('owner');
        $template = $this->createMock(Template::class);
        $assigned = [];
        $template->method('assign')->willReturnCallback(static function (string $name, mixed $value) use (&$assigned): void {
            $assigned[$name] = $value;
        });
        $template->method('display')->willReturn(new Response('Rendered settings page without a credential field value.'));
        $controller = new AiSettingsController($template, new AiConfiguration, $this->createMock(RateLimiter::class));
        $secret = 'test-only-pasted-api-key';

        $save = $controller->save($this->request(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'api_key' => $secret]));
        self::assertSame(302, $save->getStatusCode());
        self::assertSame(BASE_URL.'/agent/settings', $save->headers->get('Location'));
        self::assertSame('saved', session('agent_ai_settings_status'));
        self::assertStringNotContainsString($secret, (string) $save->headers->get('Location'));
        self::assertStringNotContainsString($secret, (string) $save->getContent());
        self::assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertSame($secret, Crypt::decryptString((string) $this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key')));

        $show = $controller->show();
        self::assertSame(200, $show->getStatusCode());
        self::assertTrue($show->headers->hasCacheControlDirective('private'));
        self::assertTrue($show->headers->hasCacheControlDirective('no-store'));
        self::assertSame('website', $assigned['aiConfiguration']['source']);
        self::assertTrue($assigned['aiConfiguration']['hasKey']);
        self::assertStringNotContainsString($secret, json_encode($assigned, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, (string) $show->getContent());
    }

    public function test_owner_can_save_brave_key_without_exposing_it_or_changing_model_settings(): void
    {
        $this->authenticate('owner');
        $template = $this->createMock(Template::class);
        $assigned = [];
        $template->method('assign')->willReturnCallback(static function (string $name, mixed $value) use (&$assigned): void {
            $assigned[$name] = $value;
        });
        $template->method('display')->willReturn(new Response('Settings page'));
        $controller = new AiSettingsController($template, new AiConfiguration, $this->createMock(RateLimiter::class));
        $secret = 'test-only-brave-key';

        $save = $controller->saveWebSearch($this->request(['web_search_api_key' => $secret]));
        self::assertSame(302, $save->getStatusCode());
        self::assertSame('saved', session('agent_web_search_status'));
        self::assertSame(BASE_URL.'/agent/settings', $save->headers->get('Location'));
        self::assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->db->table('julianna_agent_ai_settings')->count());
        $ciphertext = (string) $this->db->table('julianna_agent_web_search_settings')->value('encrypted_api_key');
        self::assertNotSame($secret, $ciphertext);
        self::assertSame($secret, Crypt::decryptString($ciphertext));

        $show = $controller->show();
        self::assertSame(200, $show->getStatusCode());
        self::assertSame(['hasKey' => true, 'source' => 'website'], $assigned['webSearchConfiguration']);
        self::assertTrue($assigned['webSearchConfigured']);
        self::assertStringNotContainsString($secret, json_encode($assigned, JSON_THROW_ON_ERROR));
        self::assertTrue($show->headers->hasCacheControlDirective('no-store'));

        $clear = $controller->clearWebSearch();
        self::assertSame(302, $clear->getStatusCode());
        self::assertSame('cleared', session('agent_web_search_status'));
        self::assertNull($this->db->table('julianna_agent_web_search_settings')->value('encrypted_api_key'));
    }

    public function test_brave_key_submission_requires_https_off_loopback(): void
    {
        $this->authenticate('owner');
        $controller = new AiSettingsController(
            $this->createMock(Template::class),
            new AiConfiguration,
            $this->createMock(RateLimiter::class),
        );
        $response = $controller->saveWebSearch(Request::create('http://remote.example/agent/settings/web-search', 'POST', [
            'web_search_api_key' => 'test-only-brave-key',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('requires_https', session('agent_web_search_status'));
        self::assertSame(0, $this->db->table('julianna_agent_web_search_settings')->count());
    }

    public function test_connection_check_without_configuration_never_calls_a_provider_and_is_rate_limited(): void
    {
        $this->authenticate('owner');
        (new AiConfiguration)->clear(7); // Suppress any deployment env fallback in this isolated DB.
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->expects(self::exactly(2))->method('tooManyAttempts')->willReturnOnConsecutiveCalls(false, true);
        $limiter->expects(self::once())->method('hit');
        $controller = new AiSettingsController($this->createMock(Template::class), new AiConfiguration, $limiter);

        $missing = $controller->test($this->request());
        self::assertSame(302, $missing->getStatusCode());
        self::assertSame('not_configured', session('agent_ai_settings_status'));
        self::assertTrue($missing->headers->hasCacheControlDirective('private'));
        self::assertTrue($missing->headers->hasCacheControlDirective('no-store'));

        $limited = $controller->test($this->request());
        self::assertSame(302, $limited->getStatusCode());
        self::assertSame('test_limited', session('agent_ai_settings_status'));
    }

    public function test_plain_http_on_a_non_loopback_host_cannot_submit_a_credential(): void
    {
        $this->authenticate('owner');
        $controller = new AiSettingsController(
            $this->createMock(Template::class),
            new AiConfiguration,
            $this->createMock(RateLimiter::class),
        );

        $request = Request::create('http://remote.example/agent/settings', 'POST', [
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'api_key' => 'test-only-pasted-api-key',
        ]);
        $response = $controller->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('requires_https', session('agent_ai_settings_status'));
        self::assertSame(0, $this->db->table('julianna_agent_ai_settings')->count());
        self::assertStringNotContainsString('test-only-pasted-api-key', (string) $response->getContent());
    }

    public function test_model_picker_supports_preset_and_custom_ids_without_reentering_the_same_provider_key(): void
    {
        $this->authenticate('owner');
        $controller = new AiSettingsController(
            $this->createMock(Template::class),
            new AiConfiguration,
            $this->createMock(RateLimiter::class),
        );

        $preset = $controller->save($this->request([
            'provider' => 'deepseek',
            'model_choice' => 'deepseek-flash',
            'api_key' => 'test-only-pasted-api-key',
        ]));
        self::assertSame(302, $preset->getStatusCode());
        self::assertSame('deepseek-flash', $this->db->table('julianna_agent_ai_settings')->value('model'));

        $custom = $controller->save($this->request([
            'provider' => 'deepseek',
            'model_choice' => '__custom__',
            'model_custom' => 'deepseek-new-model',
            'api_key' => '',
        ]));
        self::assertSame(302, $custom->getStatusCode());
        self::assertSame('deepseek-new-model', $this->db->table('julianna_agent_ai_settings')->value('model'));
        self::assertSame('test-only-pasted-api-key', Crypt::decryptString((string) $this->db->table('julianna_agent_ai_settings')->value('encrypted_api_key')));
    }

    public function test_model_lookup_uses_a_saved_key_without_returning_it_to_the_browser(): void
    {
        $this->authenticate('owner');
        (new AiConfiguration)->save('openai', 'saved-model', 'test-only-openai-key', 7);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options): HttpResponse {
                self::assertSame('Bearer test-only-openai-key', $options['headers']['Authorization']);

                return new HttpResponse(200, [], '{"data":[{"id":"live-model"}]}');
            },
        );
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->method('tooManyAttempts')->willReturn(false);
        $controller = new AiSettingsController($this->createMock(Template::class), new AiConfiguration, $limiter);

        // Match the production request after ConvertEmptyStringsToNull.
        $response = $controller->models($this->request(['provider' => 'openai', 'api_key' => null]), new ProviderModelCatalog($http));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['models' => ['live-model']], json_decode((string) $response->getContent(), true));
        self::assertStringNotContainsString('test-only-openai-key', (string) $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_model_lookup_rejects_unknown_provider_without_network_access(): void
    {
        $this->authenticate('owner');
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->method('tooManyAttempts')->willReturn(false);
        $controller = new AiSettingsController($this->createMock(Template::class), new AiConfiguration, $limiter);

        $response = $controller->models(
            $this->request(['provider' => 'https://attacker.example', 'api_key' => 'test-key']),
            new ProviderModelCatalog($http),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('test-key', (string) $response->getContent());
    }

    private function authenticate(string $role): void
    {
        session()->put('userdata', ['id' => 7, 'role' => $role]);
        session()->put('julianna_auth.authenticated_account_id', 11);
        session()->put('julianna_auth.session_version', 1);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body = []): Request
    {
        return Request::create('https://julianna.example/agent/settings', 'POST', $body);
    }
}
