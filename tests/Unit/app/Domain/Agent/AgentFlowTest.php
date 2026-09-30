<?php

declare(strict_types=1);

namespace Unit\app\Domain\Agent;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Domain\Agent\Repositories\AgentRepository;
use Leantime\Domain\Agent\Services\Agent;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\IdeaRoom\AI\AiProvider;
use Leantime\Domain\IdeaRoom\AI\AssistantTurn;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\ChatResponse;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\ToolCall;
use Leantime\Domain\IdeaRoom\Tools\IdeaRoomToolRegistry;
use Leantime\Domain\Install\Services\SchemaBuilder;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\Mcp\Services\SchemaValidator;
use Leantime\Domain\Mcp\Services\ToolCatalog;
use Leantime\Domain\Mcp\Services\ToolDispatcher;
use Leantime\Domain\ProjectAgent\Repositories\ProjectAgentRepository;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use Unit\TestCase;

final class AgentFlowTest extends TestCase
{
    private SQLiteConnection $db;

    private ProjectAgentRepository $projectStore;

    private Tickets&MockObject $tickets;

    private ToolCatalog $catalog;

    private ToolDispatcher $dispatcher;

    private ProjectAgent $projectAgent;

    private PermissionService&MockObject $permissions;

    protected function setUp(): void
    {
        parent::setUp();
        session()->put('userdata.id', 7);
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->db->getSchemaBuilder();
        $schema->create('julianna_auth_accounts', static function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('user_id')->nullable();
            $table->string('email_normalized'); $table->string('display_name'); $table->string('state');
            $table->dateTime('email_verified_at')->nullable(); $table->dateTime('approved_at')->nullable();
            $table->dateTime('mfa_confirmed_at')->nullable(); $table->unsignedInteger('session_version');
            $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        $schema->create('zp_user', static function (Blueprint $table): void {
            $table->increments('id'); $table->string('firstname'); $table->string('username');
            $table->unsignedInteger('role'); $table->string('status'); $table->unsignedInteger('clientId')->nullable();
            $table->string('settings')->nullable();
        });
        $schema->create('zp_projects', static function (Blueprint $table): void {
            $table->increments('id'); $table->string('state'); $table->string('psettings');
            $table->unsignedInteger('clientId')->nullable();
        });
        $schema->create('zp_relationuserproject', static function (Blueprint $table): void {
            $table->unsignedInteger('userId'); $table->unsignedInteger('projectId');
        });
        Schema::swap($schema);
        $builder = new SchemaBuilder(new AppSettings);
        $builder->createProjectAgentTables();
        $builder->createAgentHarnessTables();
        $builder->createAgentActionClaimsTable();
        $this->db->table('zp_user')->insert([
            'id' => 7, 'firstname' => 'Editor', 'username' => 'editor@example.test',
            'role' => 20, 'status' => 'a', 'clientId' => null, 'settings' => null,
        ]);
        $this->db->table('julianna_auth_accounts')->insert([
            'user_id' => 7, 'email_normalized' => 'editor@example.test', 'display_name' => 'Editor',
            'state' => 'active', 'email_verified_at' => '2026-01-01 00:00:00',
            'approved_at' => '2026-01-01 00:00:00', 'mfa_confirmed_at' => '2026-01-01 00:00:00',
            'session_version' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->db->table('zp_projects')->insert(['id' => 3, 'state' => '0', 'psettings' => 'private']);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 7, 'projectId' => 3]);
        $this->db->table('julianna_agent_projects')->insert([
            'project_id' => 3, 'enabled' => true, 'paused' => false,
            'enabled_by_user_id' => 7, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('currentUserCan')->willReturn(true);
        $this->permissions = $permissions;
        $projects = $this->createMock(Projects::class);
        $projects->method('getProject')->willReturn(['id' => 3, 'name' => 'Workshop']);
        $this->tickets = $this->createMock(Tickets::class);
        $goals = $this->createMock(Goalcanvas::class);
        $platform = new IdeaRoomToolRegistry($permissions, $projects, $this->tickets, $goals, $this->createMock(Calendar::class));
        $this->catalog = new ToolCatalog($platform);
        $whiteboards = (new \ReflectionClass(Whiteboards::class))->newInstanceWithoutConstructor();
        $this->dispatcher = new ToolDispatcher($this->catalog, new SchemaValidator, $platform, $permissions,
            $this->db, new AccountRepository($this->db), $this->tickets, $goals, $whiteboards);
        $this->projectStore = new ProjectAgentRepository($this->db);
        $this->projectAgent = new ProjectAgent($this->projectStore, $permissions);
    }

    public function test_clear_request_executes_once_without_approval_and_persists_receipt(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturnCallback(function (): int {
            // This callback is the effect boundary: the audit entry must be
            // durable before the domain service can make the first write.
            self::assertSame('pending', $this->db->table('julianna_agent_activities')->value('status'));
            self::assertSame('pending', $this->db->table('julianna_agent_tool_receipts')->value('status'));

            return 77;
        });
        $provider = new QueueAgentProvider([
            new AssistantTurn('I will add that task.', [new ToolCall('call-task-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Prepare workshop',
            ])], 'tool_calls'),
            new AssistantTurn('Task 77 is ready.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3, '/projects/3');
        $first = $agent->turn($conversation['id'], 7, 'Add a workshop preparation task', 'request-unique-123456');
        self::assertCount(4, $first['turns']);
        self::assertSame('completed', $this->db->table('julianna_agent_tool_receipts')->value('status'));
        self::assertSame('completed', $this->db->table('julianna_agent_activities')->value('status'));
        self::assertSame(1, $this->db->table('julianna_agent_activities')->count());
        $replayed = $agent->turn($conversation['id'], 7, 'Add a workshop preparation task', 'request-unique-123456');
        self::assertSame($first, $replayed);
        self::assertSame(2, $provider->calls);
    }

    public function test_direct_scoped_request_can_create_task_without_autopilot_activation(): void
    {
        $this->db->table('julianna_agent_projects')->where('project_id', 3)->delete();
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(91);
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the requested task.', [new ToolCall('call-direct-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Find new clients',
            ])], 'tool_calls'),
            new AssistantTurn('The task is ready.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Add a task to find new clients', 'request-direct-123456');

        self::assertContains('addTask', $provider->seenToolNames[0]);
        self::assertStringContainsString('Current available tool names for this request:', $provider->seenSystemPrompts[0]);
        self::assertStringContainsString('addTask', $provider->seenSystemPrompts[0]);
        self::assertSame('completed', $this->db->table('julianna_agent_tool_receipts')->value('status'));
        self::assertSame('completed', $this->db->table('julianna_agent_activities')->value('status'));
    }

    public function test_stale_task_tool_claim_is_not_replayed_and_new_false_refusal_is_corrected(): void
    {
        session()->put('usersettings.language', 'en-US');
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(92);
        $provider = new QueueAgentProvider([
            new AssistantTurn('I cannot create tasks because no task tool is available.', [], 'completed'),
            new AssistantTurn('I will add the task now.', [new ToolCall('call-corrected-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Find prospective clients',
            ])], 'tool_calls'),
            new AssistantTurn('Created the prospective-client task.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $store = new AgentRepository($this->db, $this->projectStore);
        $store->addTurn($conversation['id'], 'user', 'Can you create tasks?');
        $store->addTurn($conversation['id'], 'assistant', 'I cannot create tasks because no task tool is available.');

        $result = $agent->turn($conversation['id'], 7, 'Create a task to find prospective clients', 'request-corrected-123456');

        self::assertSame(3, $provider->calls);
        self::assertSame('An earlier assistant claim about missing task tools was outdated. The current tool list is authoritative.',
            $provider->seenMessages[0][1]['content']);
        self::assertStringContainsString('addTask and bulkAddTasks are supplied', $provider->seenSystemPrompts[1]);
        self::assertSame('I cannot create tasks because no task tool is available.', $result['turns'][1]['content']);
        self::assertSame('completed', $this->db->table('julianna_agent_tool_receipts')->value('status'));
        self::assertCount(1, $this->db->table('julianna_agent_tool_receipts')->get());
    }

    public function test_two_false_task_tool_refusals_become_an_accurate_non_success_response(): void
    {
        session()->put('usersettings.language', 'en-US');
        $this->tickets->expects(self::never())->method('quickAddTicket');
        $provider = new QueueAgentProvider([
            new AssistantTurn('No tool can create tasks.', [], 'completed'),
            new AssistantTurn('No tool can create tasks.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Create client-outreach tasks', 'request-corrected-fallback-123456');

        self::assertSame(2, $provider->calls);
        self::assertStringContainsString('I can create tasks', $result['turns'][1]['content']);
        self::assertStringContainsString('No tasks were created', $result['turns'][1]['content']);
        self::assertSame(0, $this->db->table('julianna_agent_tool_receipts')->count());
    }

    public function test_paused_autopilot_keeps_direct_write_tools_but_stops_background_turns(): void
    {
        $this->db->table('julianna_agent_projects')->where('project_id', 3)->update(['paused' => true]);
        $interactiveProvider = new QueueAgentProvider([new AssistantTurn('Ready.', [], 'completed')]);
        $agent = $this->agent($interactiveProvider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'What can you do?', 'request-paused-direct-123456');
        self::assertContains('addTask', $interactiveProvider->seenToolNames[0]);

        $backgroundProvider = new QueueAgentProvider([]);
        $backgroundAgent = $this->agent($backgroundProvider);
        $backgroundConversation = $backgroundAgent->createConversation(7, 3, source: 'background');
        try {
            $backgroundAgent->turn($backgroundConversation['id'], 7, 'Review project', 'request-paused-background-123456', 'background');
            self::fail('Paused autopilot was allowed to run in the background.');
        } catch (\Leantime\Core\Exceptions\AuthorizationException) {
            self::assertSame(0, $backgroundProvider->calls);
        }
    }

    public function test_exception_at_effect_boundary_leaves_visible_uncertain_activity_without_retry(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturnCallback(function (): never {
            self::assertSame('pending', $this->db->table('julianna_agent_activities')->value('status'));
            throw new \RuntimeException('Domain write outcome is unknown.');
        });
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the task.', [new ToolCall('call-uncertain-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Prepare workshop',
            ])], 'tool_calls'),
            new AssistantTurn('Please check the activity before continuing.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $first = $agent->turn($conversation['id'], 7, 'Add a workshop task', 'request-uncertain-123456');

        self::assertSame('pending', $this->db->table('julianna_agent_activities')->value('status'));
        self::assertSame('pending', $this->db->table('julianna_agent_tool_receipts')->value('status'));
        self::assertStringContainsString('not yet confirmed',
            (string) $this->db->table('julianna_agent_activities')->value('outcome'));
        self::assertSame($first, $agent->turn($conversation['id'], 7, 'Add a workshop task', 'request-uncertain-123456'));
        self::assertSame(2, $provider->calls);
    }

    public function test_completed_domain_write_stays_visible_when_activity_completion_fails(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(77);
        $failingStore = new class($this->db) extends ProjectAgentRepository {
            public function finishActivity(
                int $projectId,
                int $activityId,
                int $actorId,
                string $outcome,
                string $status,
                ?array $recovery,
            ): ?array {
                throw new \RuntimeException('Simulated post-write activity failure.');
            }
        };
        $projectAgent = new ProjectAgent($failingStore, $this->permissions);
        $agent = new Agent(new AgentRepository($this->db, $failingStore), $this->catalog,
            $this->dispatcher, $projectAgent, $this->permissions, new QueueAgentProvider([
                new AssistantTurn('Adding the task.', [new ToolCall('call-postwrite-1', 'addTask', [
                    'projectId' => 3, 'headline' => 'Prepare workshop',
                ])], 'tool_calls'),
                new AssistantTurn('Please check the activity before continuing.', [], 'completed'),
            ]));
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Add a workshop task', 'request-postwrite-123456');

        self::assertSame(1, $this->db->table('julianna_agent_activities')->count());
        self::assertSame('pending', $this->db->table('julianna_agent_activities')->value('status'));
        self::assertSame('pending', $this->db->table('julianna_agent_tool_receipts')->value('status'));
    }

    public function test_model_cannot_retry_same_write_with_new_call_id_in_one_request(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(77);
        $arguments = ['projectId' => 3, 'headline' => 'Prepare workshop'];
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the task.', [new ToolCall('call-first-1', 'addTask', $arguments)], 'tool_calls'),
            new AssistantTurn('Trying it again.', [new ToolCall('call-second-2', 'addTask', [
                'headline' => 'Prepare workshop', 'projectId' => 3,
            ])], 'tool_calls'),
            new AssistantTurn('The task was handled once.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Add a workshop task', 'request-noretry-123456');

        self::assertSame(1, $this->db->table('julianna_agent_activities')->count());
        self::assertSame('completed', $this->db->table('julianna_agent_activities')->value('status'));
        self::assertSame(2, $this->db->table('julianna_agent_tool_receipts')->count());
        self::assertStringContainsString('not repeated', $result['turns'][4]['content']);
    }

    public function test_explicit_tool_failure_completes_activity_as_failed(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(false);
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the task.', [new ToolCall('call-failed-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Prepare workshop',
            ])], 'tool_calls'),
            new AssistantTurn('The task could not be added.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Add a workshop task', 'request-failed-123456');

        self::assertSame('failed', $this->db->table('julianna_agent_activities')->value('status'));
        self::assertSame('failed', $this->db->table('julianna_agent_tool_receipts')->value('status'));
    }

    public function test_activity_completion_is_a_single_pending_to_final_transition(): void
    {
        $pending = $this->projectAgent->recordActivity(3, 7, [
            'action' => 'addTask',
            'rationale' => 'Requested in the agent conversation.',
            'outcome' => 'Outcome not yet confirmed.',
            'status' => 'pending',
            'idempotencyKey' => 'transition-test',
        ]);
        self::assertSame('pending', $pending['status']);
        self::assertFalse($pending['recoveryAvailable']);
        $completed = $this->projectAgent->finishActivity(3, (int) $pending['id'], 7,
            'Task created.', 'completed');
        self::assertSame('completed', $completed['status']);
        self::assertSame('Task created.', $completed['outcome']);

        $this->expectException(\RuntimeException::class);
        $this->projectAgent->finishActivity(3, (int) $pending['id'], 7, 'Task failed.', 'failed');
    }

    public function test_unscoped_conversation_cannot_write_even_if_model_requests_it(): void
    {
        $this->tickets->expects(self::never())->method('quickAddTicket');
        $provider = new QueueAgentProvider([
            new AssistantTurn('Trying to add a task.', [new ToolCall('call-task-2', 'addTask', [
                'projectId' => 3, 'headline' => 'Should not exist',
            ])], 'tool_calls'),
            new AssistantTurn('NEEDS_INPUT: Which project should I use?', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, null);
        $result = $agent->turn($conversation['id'], 7, 'Add a task', 'request-unique-999999');
        self::assertNotContains('addTask', $provider->seenToolNames[0]);
        $availableNames = explode('Current available tool names for this request:', $provider->seenSystemPrompts[0], 2)[1];
        self::assertStringNotContainsString('addTask', $availableNames);
        self::assertSame(0, $this->db->table('julianna_agent_activities')->count());
        self::assertSame(1, $this->db->table('julianna_agent_questions')->count());
        self::assertSame('Which project should I use?', $result['turns'][3]['content']);
    }

    public function test_markdown_question_marker_is_recorded_but_not_shown(): void
    {
        $provider = new QueueAgentProvider([
            new AssistantTurn('**NEEDS_INPUT:** Which goal board should I use?', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Add a goal', 'request-goal-question-123456');

        self::assertSame('Which goal board should I use?', $result['turns'][1]['content']);
        self::assertSame(1, $this->db->table('julianna_agent_questions')->count());
        self::assertSame('Which goal board should I use?', $this->db->table('julianna_agent_questions')->value('question'));
    }

    public function test_communication_is_saved_as_draft_without_publication(): void
    {
        $provider = new QueueAgentProvider([
            new AssistantTurn('I will draft the update.', [new ToolCall('call-draft-1', 'addProjectStatusUpdate', [
                'projectId' => 3, 'text' => 'Workshop prep is underway.', 'status' => 'green',
            ])], 'tool_calls'),
            new AssistantTurn('The update is ready as a draft for your review.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Draft a project update', 'request-draft-123456');
        self::assertSame('draft', $this->db->table('julianna_agent_drafts')->value('status'));
        self::assertSame('draft', $this->db->table('julianna_agent_activities')->value('status'));
    }

    public function test_provider_failure_after_a_write_is_terminal_and_replay_safe(): void
    {
        session()->put('usersettings.language', 'en-US');
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(77);
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the task.', [new ToolCall('call-task-3', 'addTask', [
                'projectId' => 3, 'headline' => 'Prepare workshop',
            ])], 'tool_calls'),
            new ProviderException('Provider unavailable'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $first = $agent->turn($conversation['id'], 7, 'Add a task', 'request-failure-123456');
        self::assertCount(4, $first['turns']);
        self::assertStringContainsString('did not repeat any action', $first['turns'][3]['content']);
        self::assertTrue($first['turns'][3]['interrupted']);
        self::assertSame('idle', $first['conversation']['state']);

        $replayed = $agent->turn($conversation['id'], 7, 'Add a task', 'request-failure-123456');
        self::assertSame($first, $replayed);
        self::assertSame(2, $provider->calls);
    }

    public function test_unknown_model_tool_leaves_interrupted_replay_not_false_success(): void
    {
        $provider = new QueueAgentProvider([
            new AssistantTurn('Trying a tool.', [new ToolCall('call-unknown-1', 'unknownTool', [])], 'tool_calls'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        try {
            $agent->turn($conversation['id'], 7, 'Help with the project', 'request-unknown-123456');
            self::fail('Unknown model tool should be rejected.');
        } catch (\InvalidArgumentException) {
            // The failed request is recorded as interrupted before a replay.
        }

        $replayed = $agent->turn($conversation['id'], 7, 'Help with the project', 'request-unknown-123456');
        self::assertTrue($replayed['turns'][2]['interrupted']);
        self::assertSame('idle', $replayed['conversation']['state']);
        self::assertSame(1, $provider->calls);
    }

    public function test_model_uses_whiteboard_patch_not_full_scene_replacement(): void
    {
        $provider = new QueueAgentProvider([new AssistantTurn('The board is ready to edit.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Help with the board', 'request-board-123456');

        self::assertContains('patchWhiteboardScene', $provider->seenToolNames[0]);
        self::assertNotContains('saveWhiteboardScene', $provider->seenToolNames[0]);
    }

    public function test_stale_running_turn_is_interrupted_before_a_new_request(): void
    {
        session()->put('usersettings.language', 'en-US');
        $provider = new QueueAgentProvider([new AssistantTurn('Ready to continue.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $this->db->table('julianna_agent_conversations')->where('id', $conversation['id'])->update([
            'state' => 'running',
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 1300),
        ]);
        $this->db->table('julianna_agent_turns')->insert([
            'conversation_id' => $conversation['id'], 'role' => 'user',
            'content' => 'Earlier request', 'metadata_json' => null,
            'client_key' => hash('sha256', 'request-stale-old-1234'),
            'created_at' => gmdate('Y-m-d H:i:s', time() - 1300),
        ]);

        $result = $agent->turn($conversation['id'], 7, 'Continue safely', 'request-stale-new-1234');
        self::assertTrue($result['turns'][1]['interrupted']);
        self::assertSame('Ready to continue.', $result['turns'][3]['content']);
        self::assertSame('idle', $result['conversation']['state']);
    }

    public function test_background_event_and_daily_runs_share_a_project_action_claim(): void
    {
        $this->tickets->expects(self::once())->method('quickAddTicket')->willReturn(88);
        $provider = new QueueAgentProvider([
            new AssistantTurn('Adding the task.', [new ToolCall('call-event-1', 'addTask', [
                'projectId' => 3, 'headline' => 'Prepare workshop',
            ])], 'tool_calls'),
            new AssistantTurn('Done.', [], 'completed'),
            new AssistantTurn('Adding the task.', [new ToolCall('call-daily-1', 'addTask', [
                'headline' => 'Prepare workshop', 'projectId' => 3,
            ])], 'tool_calls'),
            new AssistantTurn('Already handled.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $first = $agent->createConversation(7, 3);
        $second = $agent->createConversation(7, 3);
        $agent->turn($first['id'], 7, 'Review event', 'request-event-123456', 'background');
        $agent->turn($second['id'], 7, 'Review daily', 'request-daily-123456', 'background');

        self::assertSame(1, $this->db->table('julianna_agent_action_claims')->count());
        self::assertSame('completed', $this->db->table('julianna_agent_action_claims')->value('status'));
        self::assertSame(1, $this->db->table('julianna_agent_activities')->count());
    }

    public function test_interrupted_multi_tool_round_is_repaired_for_provider_context(): void
    {
        $provider = new QueueAgentProvider([new AssistantTurn('I can continue safely.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $id = (int) $conversation['id'];
        $store = new AgentRepository($this->db, $this->projectStore);
        $store->addTurn($id, 'user', 'Earlier task');
        $store->addTurn($id, 'assistant', 'I will check two items.', ['tool_calls' => [
            ['id' => 'call-a', 'name' => 'getProject', 'arguments' => ['projectId' => 3]],
            ['id' => 'call-b', 'name' => 'getProject', 'arguments' => ['projectId' => 3]],
        ]]);
        $store->addTurn($id, 'tool', '{"ok":true}', ['tool_call_id' => 'call-a', 'name' => 'getProject']);
        $this->db->table('julianna_agent_conversations')->where('id', $id)->update([
            'state' => 'running', 'updated_at' => gmdate('Y-m-d H:i:s', time() - 1300),
        ]);

        $agent->turn($id, 7, 'Continue', 'request-after-crash-123456');
        $messages = $provider->seenMessages[0];
        self::assertSame('tool', $messages[3]['role']);
        self::assertSame('call-b', $messages[3]['tool_call_id']);
        self::assertTrue($messages[3]['is_error']);
        self::assertSame('assistant', $messages[4]['role']);
        self::assertSame('user', $messages[5]['role']);
    }

    public function test_provider_reasoning_is_reused_server_side_but_not_exposed_to_ui(): void
    {
        $provider = new QueueAgentProvider([
            new AssistantTurn('Checking.', [new ToolCall('call-reasoning-1', 'getProject', [
                'projectId' => 3,
            ])], 'tool_calls', 'private provider reasoning'),
            new AssistantTurn('The project is ready.', [], 'completed'),
        ]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Check the project', 'request-reasoning-123456');

        self::assertSame('private provider reasoning', $provider->seenMessages[1][1]['reasoning_content']);
        self::assertArrayNotHasKey('reasoning_content', $result['turns'][1]);
    }

    public function test_background_prompt_uses_enabling_users_saved_language(): void
    {
        $this->db->table('zp_user')->where('id', 7)->update(['settings' => serialize(['language' => 'en-US'])]);
        session()->put('usersettings.language', 'fr-CH');
        $provider = new QueueAgentProvider([new AssistantTurn('Review complete.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Review the project', 'request-locale-123456', 'background');

        self::assertSame('en-US', $provider->seenLocales[0]);
    }

    public function test_interactive_reply_uses_message_language_instead_of_interface_language(): void
    {
        session()->put('usersettings.language', 'fr-CH');
        $provider = new QueueAgentProvider([new AssistantTurn('Done.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Please add a task for our project', 'request-english-language-123456');

        self::assertSame('en-US', $provider->seenLocales[0]);
        self::assertStringContainsString('Reply locale for this turn: English', $provider->seenSystemPrompts[0]);

        session()->put('usersettings.language', 'en-US');
        $provider = new QueueAgentProvider([new AssistantTurn('Terminé.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $agent->turn($conversation['id'], 7, 'Peux-tu ajouter une tâche dans mon projet ?', 'request-french-language-123456');

        self::assertSame('fr-CH', $provider->seenLocales[0]);
        self::assertStringContainsString('Reply locale for this turn: Swiss French', $provider->seenSystemPrompts[0]);
    }

    public function test_provider_failure_uses_the_message_language(): void
    {
        session()->put('usersettings.language', 'fr-CH');
        $provider = new QueueAgentProvider([new ProviderException('Provider unavailable')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Please check my project', 'request-error-language-123456');

        self::assertStringContainsString('The AI provider stopped', $result['turns'][1]['content']);
    }

    public function test_invalid_provider_key_gives_safe_actionable_guidance(): void
    {
        session()->put('usersettings.language', 'en-US');
        $provider = new QueueAgentProvider([new ProviderException('private provider diagnostics', 401)]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $result = $agent->turn($conversation['id'], 7, 'Check my project', 'request-invalid-key-123456');

        self::assertStringContainsString('rejected the saved API key', $result['turns'][1]['content']);
        self::assertStringContainsString('Agent settings', $result['turns'][1]['content']);
        self::assertStringContainsString('did not repeat any action', $result['turns'][1]['content']);
        self::assertStringNotContainsString('private provider diagnostics', $result['turns'][1]['content']);
        self::assertTrue($result['turns'][1]['interrupted']);

        session()->put('usersettings.language', 'fr-CH');
        $provider = new QueueAgentProvider([new ProviderException('private provider diagnostics', 401)]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $french = $agent->turn($conversation['id'], 7, 'Vérifie mon projet', 'request-invalid-key-french-123456');
        self::assertStringContainsString('clé API enregistrée', $french['turns'][1]['content']);
        self::assertStringNotContainsString('private provider diagnostics', $french['turns'][1]['content']);
    }

    public function test_background_review_does_not_depend_on_a_browser_session(): void
    {
        session()->forget('userdata');
        $provider = new QueueAgentProvider([new AssistantTurn('Review complete.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3, source: 'background');
        $result = $agent->turn($conversation['id'], 7, 'Review the project', 'request-no-session-123456', 'background');

        self::assertSame('Review complete.', $result['turns'][1]['content']);
    }

    public function test_long_conversation_keeps_recent_complete_rounds_within_provider_budget(): void
    {
        $provider = new QueueAgentProvider([new AssistantTurn('Latest request handled.', [], 'completed')]);
        $agent = $this->agent($provider);
        $conversation = $agent->createConversation(7, 3);
        $store = new AgentRepository($this->db, $this->projectStore);
        for ($number = 0; $number < 30; $number++) {
            $store->addTurn($conversation['id'], 'user', 'Prior request '.$number.' '.str_repeat('x', 4800));
            $store->addTurn($conversation['id'], 'assistant', 'Prior answer '.$number.' '.str_repeat('y', 4800));
        }
        $agent->turn($conversation['id'], 7, 'Newest request', 'request-context-123456');

        $messages = $provider->seenMessages[0];
        self::assertLessThan(180001, strlen(json_encode($messages, JSON_THROW_ON_ERROR)));
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Newest request', $messages[array_key_last($messages)]['content']);
        self::assertLessThan(61, count($messages));
    }

    private function agent(AiProvider $provider): Agent
    {
        return new Agent(new AgentRepository($this->db, $this->projectStore), $this->catalog,
            $this->dispatcher, $this->projectAgent, $this->permissions, $provider);
    }
}

final class QueueAgentProvider implements AiProvider
{
    public int $calls = 0;
    /** @var list<list<string>> */
    public array $seenToolNames = [];
    /** @var list<list<array<string,mixed>>> */
    public array $seenMessages = [];
    /** @var list<string> */
    public array $seenLocales = [];
    /** @var list<string> */
    public array $seenSystemPrompts = [];

    /** @param list<AssistantTurn|ProviderException> $turns */
    public function __construct(private array $turns) {}

    public function respond(ChatRequest $request): ChatResponse
    {
        throw new \LogicException('Not used by the agent harness.');
    }

    public function turn(ChatRequest $request): AssistantTurn
    {
        $this->calls++;
        $this->seenToolNames[] = array_map(static fn ($tool): string => $tool->name, $request->tools);
        $this->seenMessages[] = $request->messages;
        $this->seenLocales[] = $request->locale;
        $this->seenSystemPrompts[] = (string) $request->systemPrompt;

        $next = array_shift($this->turns) ?? throw new \LogicException('No queued turn.');
        if ($next instanceof ProviderException) {
            throw $next;
        }

        return $next;
    }

    public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn
    {
        return $this->turn($request);
    }
}
