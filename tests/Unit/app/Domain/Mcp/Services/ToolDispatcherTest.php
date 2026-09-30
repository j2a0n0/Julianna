<?php

declare(strict_types=1);

namespace Unit\app\Domain\Mcp\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Laravel\Mcp\Server;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Blueprints\Services\Blueprints;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\Mcp\Services\SchemaValidator;
use Leantime\Domain\Mcp\Services\PlatformToolRegistry;
use Leantime\Domain\Mcp\Services\ToolCatalog;
use Leantime\Domain\Mcp\Services\ToolDispatcher;
use Leantime\Domain\Mcp\Services\WebSearch;
use Leantime\Domain\Mcp\Server\JuliannaServer;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use Leantime\Domain\Whiteboards\Services\WhiteboardAccess;
use Leantime\Domain\Whiteboards\Support\WhiteboardConflictException;
use PDO;
use Unit\TestCase;

final class ToolDispatcherTest extends TestCase
{
    private SQLiteConnection $db;

    private ToolDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->db->getSchemaBuilder();
        $schema->create('julianna_auth_accounts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('email_normalized');
            $table->string('display_name');
            $table->string('state');
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('mfa_confirmed_at')->nullable();
            $table->unsignedInteger('session_version');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('zp_user', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('firstname');
            $table->string('username');
            $table->unsignedInteger('role');
            $table->string('status');
            $table->unsignedInteger('clientId')->nullable();
            $table->string('settings')->nullable();
            $table->string('source')->nullable();
        });
        $schema->create('zp_projects', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('state');
            $table->string('psettings');
            $table->unsignedInteger('clientId')->nullable();
        });
        $schema->create('zp_relationuserproject', static function (Blueprint $table): void {
            $table->unsignedInteger('userId');
            $table->unsignedInteger('projectId');
        });
        $schema->create('zp_canvas', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('projectId');
            $table->string('type');
            $table->string('title');
        });
        $schema->create('zp_canvas_items', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('canvasId');
            $table->string('box');
            $table->text('description');
            $table->text('data')->nullable();
            $table->text('assumptions')->nullable();
        });
        $schema->create('julianna_agent_projects', static function (Blueprint $table): void {
            $table->unsignedInteger('project_id');
            $table->boolean('enabled');
            $table->boolean('paused');
            $table->unsignedInteger('enabled_by_user_id');
        });
        $schema->create('julianna_whiteboards', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->string('title');
            $table->unsignedInteger('revision');
            $table->text('scene_json');
            $table->unsignedInteger('created_by_user_id');
            $table->unsignedInteger('updated_by_user_id');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_whiteboard_revisions', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('board_id');
            $table->unsignedInteger('revision');
            $table->text('scene_json');
            $table->unsignedInteger('author_user_id');
            $table->dateTime('created_at');
        });
        $schema->create('julianna_whiteboard_assets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('board_id');
            $table->string('file_id');
            $table->string('mime_type');
            $table->text('data_url');
            $table->unsignedInteger('byte_size');
            $table->dateTime('created_at');
        });
        $this->db->table('zp_user')->insert([
            'id' => 1, 'firstname' => 'Editor', 'username' => 'editor@example.test',
            'role' => 20, 'status' => 'a', 'clientId' => null, 'settings' => null,
            'source' => null,
        ]);
        $this->db->table('julianna_auth_accounts')->insert([
            'id' => 1, 'user_id' => 1, 'email_normalized' => 'editor@example.test',
            'display_name' => 'Editor', 'state' => 'active', 'email_verified_at' => '2026-01-01 00:00:00',
            'approved_at' => '2026-01-01 00:00:00', 'mfa_confirmed_at' => '2026-01-01 00:00:00',
            'session_version' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->db->table('zp_projects')->insert([
            ['id' => 3, 'state' => '0', 'psettings' => 'private', 'clientId' => null],
            ['id' => 4, 'state' => '0', 'psettings' => 'private', 'clientId' => null],
        ]);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 1, 'projectId' => 3]);
        $this->db->table('julianna_agent_projects')->insert([
            'project_id' => 3, 'enabled' => true, 'paused' => false, 'enabled_by_user_id' => 1,
        ]);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('roleHasPermission')->willReturn(true);
        $projects = $this->createMock(Projects::class);
        $projects->method('getProject')->willReturn(['id' => 3, 'name' => 'Project 3']);
        $tickets = $this->createMock(Tickets::class);
        $tickets->method('quickAddTicket')->willReturn(77);
        $goals = $this->createMock(Goalcanvas::class);
        $platform = new PlatformToolRegistry($permissions, $projects, $tickets, $goals, $this->createMock(Calendar::class));
        $whiteboards = new Whiteboards($this->db, new WhiteboardAccess($this->db, $permissions));
        $this->dispatcher = new ToolDispatcher(
            new ToolCatalog($platform), new SchemaValidator, $platform, $permissions,
            $this->db, new AccountRepository($this->db), $tickets, $goals, $whiteboards,
        );
        session()->put('userdata', ['id' => 1, 'role' => 'editor']);
    }

    public function test_background_call_uses_fresh_identity_then_restores_session(): void
    {
        session()->forget(['userdata', 'usersettings', 'currentProject']);
        $checked = $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3, 'background');
        self::assertSame(3, $checked['project_id']);
        self::assertFalse(session()->exists('userdata'));

        session()->put('userdata', ['id' => 1, 'role' => 'owner']);
        session()->put('currentProject', 50);
        $result = $this->dispatcher->dispatch('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3, 'interactive');
        self::assertTrue($result['ok']);
        self::assertStringContainsString('77', $result['text']);
        self::assertSame(1, session('userdata.id'));
        self::assertSame('owner', session('userdata.role'));
        self::assertSame(50, session('currentProject'));
    }

    public function test_cross_project_and_revoked_access_are_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('addTask', ['projectId' => 4, 'headline' => 'Wrong project'], 1, 3);
    }

    public function test_swot_board_scope_is_resolved_from_native_type_and_project(): void
    {
        $this->db->table('zp_canvas')->insert([
            ['id' => 11, 'projectId' => 3, 'type' => 'swotcanvas', 'title' => 'Strategy'],
            ['id' => 12, 'projectId' => 4, 'type' => 'swotcanvas', 'title' => 'Private'],
            ['id' => 13, 'projectId' => 3, 'type' => 'leancanvas', 'title' => 'Other blueprint'],
        ]);
        self::assertSame(3, $this->dispatcher->authorize('addSwotItems', [
            'boardId' => 11, 'items' => [['quadrant' => 'strengths', 'description' => 'Fast setup']],
        ], 1, 3)['project_id']);
        foreach ([12, 13] as $boardId) {
            try {
                $this->dispatcher->authorize('getSwotBlueprint', ['boardId' => $boardId], 1);
                self::fail('A foreign or non-SWOT board was authorized.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        $this->db->table('zp_relationuserproject')->where('userId', 1)->where('projectId', 3)->delete();
        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('addSwotItems', [
            'boardId' => 11, 'items' => [['quadrant' => 'strengths', 'description' => 'Fast setup']],
        ], 1, 3);
    }

    public function test_swot_entries_are_sent_to_native_blueprint_service_without_duplicate_points(): void
    {
        $this->db->table('zp_canvas')->insert(['id' => 11, 'projectId' => 3, 'type' => 'swotcanvas', 'title' => 'Strategy']);
        $this->db->table('zp_canvas_items')->insert([
            'canvasId' => 11, 'box' => 'swot_strengths', 'description' => 'Fast setup',
        ]);
        $blueprints = $this->createMock(Blueprints::class);
        $blueprints->expects(self::once())->method('createCanvasItem')
            ->with(self::callback(static fn (array $row): bool => $row['canvasId'] === 11
                && $row['box'] === 'swot_opportunities'
                && $row['description'] === 'Restaurant referrals'
                && $row['relates'] === 'relates_none'
                && $row['assumptions'] === 'Needs validation'), 'swotcanvas')
            ->willReturn('42');
        app()->instance(Blueprints::class, $blueprints);
        $result = $this->dispatcher->dispatch('addSwotItems', [
            'boardId' => 11,
            'items' => [
                ['quadrant' => 'strengths', 'description' => 'Fast setup'],
                ['quadrant' => 'opportunities', 'description' => 'Restaurant referrals', 'assumptions' => 'Needs validation'],
            ],
        ], 1, 3);
        self::assertTrue($result['ok']);
        self::assertSame(1, $result['data']['createdCount']);
        self::assertSame('opportunities', $result['data']['created'][0]['quadrant']);
    }

    public function test_interactive_caller_cannot_claim_a_different_actor(): void
    {
        session()->put('userdata', ['id' => 999, 'role' => 'owner']);

        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('getProject', ['projectId' => 3], 1);
    }

    public function test_separately_revocable_api_key_principal_is_mcp_only(): void
    {
        $this->db->table('zp_user')->insert([
            'id' => 2, 'firstname' => 'API', 'username' => 'key@example.test', 'role' => 20,
            'status' => 'a', 'clientId' => null, 'settings' => null, 'source' => 'api',
        ]);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 2, 'projectId' => 3]);
        session()->put('userdata', ['id' => 2, 'role' => 'editor']);
        self::assertSame(3, $this->dispatcher->authorize('getProject', ['projectId' => 3], 2, source: 'mcp')['project_id']);

        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('getProject', ['projectId' => 3], 2);
    }

    public function test_human_mcp_principal_advertises_the_complete_shared_catalog(): void
    {
        $names = array_column($this->dispatcher->advertisedMcpTools(), 'name');

        self::assertContains('getProject', $names);
        self::assertContains('listWhiteboards', $names);
        self::assertContains('patchWhiteboardScene', $names);
        self::assertContains('addSwotItems', $names);
    }

    public function test_live_search_is_read_only_and_does_not_require_a_selected_project(): void
    {
        $previous = $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] ?? null;
        $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = 'test-search-key';
        try {
            app()->instance(WebSearch::class, new WebSearch(new Client([
                'handler' => new MockHandler([new HttpResponse(200, [], '{"web":{"results":[{"title":"Example","url":"https://example.org/","description":"Current result"}]}}')]),
            ])));
            $result = $this->dispatcher->dispatch('searchWeb', ['query' => 'public research'], 1);
            self::assertTrue($result['ok']);
            self::assertSame('https://example.org/', $result['data']['results'][0]['url']);
            self::assertSame(1, session('userdata.id'));
            try {
                $this->dispatcher->authorize('searchWeb', ['query' => 'public research'], 1, 3, 'background');
                self::fail('Background web search was authorized.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        } finally {
            if ($previous === null) {
                unset($_ENV['JULIANNA_WEB_SEARCH_API_KEY']);
            } else {
                $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = $previous;
            }
        }
    }

    public function test_api_key_mcp_advertises_only_callable_platform_capabilities(): void
    {
        $this->db->table('zp_user')->insert([
            'id' => 2, 'firstname' => 'API', 'username' => 'key@example.test', 'role' => 20,
            'status' => 'a', 'clientId' => null, 'settings' => null, 'source' => 'api',
        ]);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 2, 'projectId' => 3]);
        session()->put('userdata', ['id' => 2, 'role' => 'editor']);

        $names = array_column($this->dispatcher->advertisedMcpTools(), 'name');
        self::assertContains('getProject', $names);
        self::assertContains('addTask', $names);
        self::assertNotContains('listWhiteboards', $names);
        self::assertNotContains('patchWhiteboardScene', $names);
        self::assertNotContains('addSwotItems', $names);

        app()->instance(ToolDispatcher::class, $this->dispatcher);
        $server = new JuliannaServer;
        $server->boot();
        $registered = (new \ReflectionProperty(Server::class, 'registeredTools'))->getValue($server);
        self::assertSame($names, array_map(static fn ($tool): string => $tool->name(), $registered));

        $this->expectException(AuthorizationException::class);
        $this->dispatcher->dispatch('listWhiteboards', ['projectId' => 3], 2, source: 'mcp');
    }

    public function test_mcp_tool_list_rechecks_principal_state(): void
    {
        self::assertNotEmpty($this->dispatcher->advertisedMcpTools());
        $this->db->table('julianna_auth_accounts')->where('id', 1)->update(['state' => 'disabled']);

        self::assertSame([], $this->dispatcher->advertisedMcpTools());
        self::assertSame(1, session('userdata.id'));
    }

    public function test_revoked_legacy_api_key_advertises_no_tools(): void
    {
        $this->db->table('zp_user')->insert([
            'id' => 2, 'firstname' => 'API', 'username' => 'key@example.test', 'role' => 20,
            'status' => 'a', 'clientId' => null, 'settings' => null, 'source' => 'api',
        ]);
        session()->put('userdata', ['id' => 2, 'role' => 'editor']);
        self::assertNotEmpty($this->dispatcher->advertisedMcpTools());
        $this->db->table('zp_user')->where('id', 2)->update(['status' => 'i']);

        self::assertSame([], $this->dispatcher->advertisedMcpTools());
    }

    public function test_revocation_is_checked_again_on_subsequent_call(): void
    {
        $this->dispatcher->authorize('getProject', ['projectId' => 3], 1);
        $this->db->table('zp_relationuserproject')->delete();

        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('getProject', ['projectId' => 3], 1);
    }

    public function test_disabled_account_is_denied_even_with_stale_session(): void
    {
        session()->put('userdata', ['id' => 1, 'role' => 'editor']);
        $this->db->table('julianna_auth_accounts')->where('id', 1)->update(['state' => 'disabled']);

        try {
            $this->dispatcher->authorize('getProject', ['projectId' => 3], 1);
            self::fail('Disabled account was accepted.');
        } catch (AuthorizationException) {
            self::assertSame(1, session('userdata.id'));
        }
    }

    public function test_autopilot_gate_blocks_background_write_but_not_direct_or_mcp_write(): void
    {
        $this->db->table('julianna_agent_projects')->where('project_id', 3)->update(['paused' => true]);
        self::assertSame(3, $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3)['project_id']);
        try {
            $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3, 'background');
            self::fail('Paused background agent was allowed to write.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        self::assertSame(3, $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, source: 'mcp')['project_id']);
    }

    public function test_unconfigured_autopilot_does_not_block_direct_write(): void
    {
        $this->db->table('julianna_agent_projects')->where('project_id', 3)->delete();

        self::assertSame(3, $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3)['project_id']);
        $this->expectException(AuthorizationException::class);
        $this->dispatcher->authorize('addTask', ['projectId' => 3, 'headline' => 'Next'], 1, 3, 'background');
    }

    public function test_communication_is_authorized_for_draft_but_not_published_by_agent(): void
    {
        $checked = $this->dispatcher->authorize('addProjectStatusUpdate', [
            'projectId' => 3, 'text' => 'Update', 'status' => 'green',
        ], 1, 3);
        self::assertSame('communication', $checked['definition']['effect']);

        $this->expectException(AuthorizationException::class);
        $this->dispatcher->dispatch('addProjectStatusUpdate', [
            'projectId' => 3, 'text' => 'Update', 'status' => 'green',
        ], 1, 3);
    }

    public function test_whiteboard_tool_text_never_contains_asset_payload(): void
    {
        $data = [
            'id' => 7, 'title' => 'Launch sketch', 'revision' => 2,
            'scene' => [
                'elements' => [['id' => 'shape-1', 'type' => 'text', 'text' => 'First move']],
                'files' => ['image-1' => ['dataURL' => 'data:image/png;base64,'.str_repeat('a', 100000)]],
            ],
        ];
        $compact = (new \ReflectionMethod(ToolDispatcher::class, 'compactWhiteboardData'))
            ->invoke($this->dispatcher, 'getWhiteboard', $data);
        $summary = (new \ReflectionMethod(ToolDispatcher::class, 'whiteboardSummary'))
            ->invoke($this->dispatcher, 'getWhiteboard', $compact);

        self::assertLessThan(60000, strlen($summary));
        self::assertStringNotContainsString('data:image', $summary);
        self::assertStringNotContainsString('dataURL', json_encode($compact, JSON_THROW_ON_ERROR));
        self::assertSame(1, json_decode($summary, true, 512, JSON_THROW_ON_ERROR)['elementCount']);
    }

    public function test_patch_whiteboard_is_revision_checked_and_returns_undo_coordinates(): void
    {
        $created = $this->dispatcher->dispatch('createWhiteboard', ['projectId' => 3, 'title' => 'Sketch'], 1, 3);
        self::assertTrue($created['ok']);
        $boardId = (int) $created['data']['boardId'];

        $patched = $this->dispatcher->dispatch('patchWhiteboardScene', [
            'boardId' => $boardId, 'expectedRevision' => 0, 'backgroundColor' => '#FFCC00',
        ], 1, 3);
        self::assertSame(1, $patched['data']['revision']);
        self::assertSame($boardId, $patched['data']['boardId']);
        self::assertArrayNotHasKey('scene', $patched['data']);
        $read = $this->dispatcher->dispatch('getWhiteboard', ['boardId' => $boardId], 1, 3);
        self::assertSame('#FFCC00', $read['data']['backgroundColor']);
        self::assertStringNotContainsString('scene', $patched['text']);

        $this->expectException(WhiteboardConflictException::class);
        $this->dispatcher->dispatch('patchWhiteboardScene', [
            'boardId' => $boardId, 'expectedRevision' => 0, 'backgroundColor' => '#000000',
        ], 1, 3);
    }

    public function test_patch_can_create_native_shapes_from_small_model_arguments(): void
    {
        $created = $this->dispatcher->dispatch('createWhiteboard', ['projectId' => 3, 'title' => 'Sketch'], 1, 3);
        $boardId = (int) $created['data']['boardId'];

        $patched = $this->dispatcher->dispatch('patchWhiteboardScene', [
            'boardId' => $boardId,
            'expectedRevision' => 0,
            'upsertElements' => [
                ['id' => 'shape-1', 'type' => 'rectangle', 'x' => 10, 'y' => 20, 'width' => 180, 'height' => 100],
                ['id' => 'note-1', 'type' => 'text', 'x' => 30, 'y' => 40, 'text' => 'First move'],
            ],
        ], 1, 3);
        self::assertSame(1, $patched['data']['revision']);
        self::assertArrayNotHasKey('scene', $patched['data']);

        $read = $this->dispatcher->dispatch('getWhiteboard', ['boardId' => $boardId], 1, 3);
        self::assertSame(2, $read['data']['elementCount']);
        self::assertSame('First move', $read['data']['elementsPreview'][1]['text']);
        $scene = json_decode((string) $this->db->table('julianna_whiteboards')->where('id', $boardId)->value('scene_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('First move', $scene['elements'][1]['originalText']);
        self::assertArrayHasKey('versionNonce', $scene['elements'][0]);
    }
}
