<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\IdeaRoom\AI\AiProvider;
use Leantime\Domain\IdeaRoom\AI\AssistantTurn;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\ChatResponse;
use Leantime\Domain\IdeaRoom\AI\ToolCall;
use Leantime\Domain\IdeaRoom\Repositories\GraphRepository;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\IdeaRoom\Services\WorkspaceChat;
use Leantime\Domain\IdeaRoom\Support\Plan;
use Leantime\Domain\IdeaRoom\Tools\IdeaRoomToolRegistry;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Projects\Tools\GetProjectTool;
use Leantime\Domain\Tickets\Services\Tickets;
use LogicException;
use PDO;
use Unit\TestCase;

final class WorkspaceChatTest extends TestCase
{
    private SQLiteConnection $connection;

    private RoomRepository $rooms;

    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        session()->put('userdata.id', 1);
        $this->connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('julianna_idea_rooms', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->string('status');
            $table->json('plan_json');
            $table->json('approval_result_json')->nullable();
            $table->unsignedInteger('graph_version')->default(0);
            $table->unsignedInteger('plan_version')->default(0);
            $table->string('mode')->default('explore');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_messages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('role');
            $table->longText('content');
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at');
        });
        $schema->create('julianna_idea_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('event_name');
            $table->json('payload_json');
            $table->dateTime('created_at');
        });
        $schema->create('julianna_idea_actions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('tool_call_id');
            $table->string('tool_name');
            $table->json('arguments_json');
            $table->string('status');
            $table->boolean('destructive');
            $table->string('idempotency_key')->unique();
            $table->json('result_json')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_generations', static function (Blueprint $table): void {
            $table->unsignedBigInteger('room_id')->primary();
            $table->string('status');
            $table->boolean('cancel_requested');
            $table->unsignedTinyInteger('tool_turns');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_graph_nodes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('type');
            $table->string('title');
            $table->text('content');
            $table->decimal('position_x');
            $table->decimal('position_y');
            $table->json('metadata_json');
            $table->unsignedBigInteger('author_user_id');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();
        });
        $schema->create('julianna_idea_graph_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('source_node_id');
            $table->unsignedBigInteger('target_node_id');
            $table->string('type');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();
            $table->boolean('deleted_with_node')->default(false);
        });
        $schema->create('julianna_idea_sources', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('url');
            $table->string('title');
            $table->text('snippet');
            $table->string('domain');
            $table->string('provider');
            $table->string('query');
            $table->dateTime('created_at');
        });
        $schema->create('julianna_idea_proposals', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('author_user_id');
            $table->string('status');
            $table->string('origin')->default('chat');
            $table->string('summary')->default('');
            $table->unsignedInteger('graph_version');
            $table->unsignedInteger('plan_version')->default(0);
            $table->json('patch_json');
            $table->json('inspiration_cards_json');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_history', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('author_user_id');
            $table->string('origin');
            $table->string('summary');
            $table->unsignedInteger('graph_version');
            $table->unsignedInteger('plan_version');
            $table->json('graph_json');
            $table->json('plan_json');
            $table->dateTime('created_at');
        });
        $this->rooms = new RoomRepository($this->connection);
        $this->roomId = (int) $this->rooms->create(1, null, 'Test room', Plan::empty())['id'];
    }

    public function test_read_only_tool_runs_automatically_and_assistant_continues(): void
    {
        $projects = $this->createMock(Projects::class);
        $projects->method('getProject')->with(2)->willReturn(['id' => 2, 'name' => 'Accessible']);
        $tool = $this->createMock(GetProjectTool::class);
        $tool->expects(self::once())->method('handle')->with(['projectId' => 2])
            ->willReturn(ToolResult::text('Accessible project found.'));
        app()->instance(GetProjectTool::class, $tool);
        $provider = $this->provider([
            new AssistantTurn('I will look it up.', [new ToolCall('call-read', 'getProject', ['projectId' => 2])], 'tool_calls'),
            new AssistantTurn('I found Accessible.', [], 'completed'),
        ]);
        $chat = $this->chat($provider, $projects);

        $chat->startTurn($this->roomId, 'Find project two');
        $chat->runTurn($this->roomId);

        self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
        self::assertSame([], $this->rooms->pendingActions($this->roomId));
        self::assertSame(['user', 'assistant', 'tool', 'assistant'], array_column($this->rooms->messages($this->roomId), 'role'));
        self::assertSame('Accessible project found.', $this->rooms->messages($this->roomId)[2]['content']);
        self::assertCount(2, $provider->requests);
        self::assertSame('tool', $provider->requests[1]->messages[2]['role']);
    }

    public function test_production_container_resolves_chat_without_a_test_provider(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        self::assertInstanceOf(WorkspaceChat::class, app()->make(WorkspaceChat::class));
    }

    public function test_room_can_be_created_for_manual_canvas_work_without_an_ai_provider(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('currentUserCan')->willReturn(true);
        $ideaRooms = new IdeaRoom($this->rooms, $permissions,
            $this->createMock(Projects::class), $this->createMock(Goalcanvas::class), $this->createMock(Tickets::class));

        $created = $ideaRooms->create('Explore a client workshop', null);

        self::assertSame('Explore a client workshop', $created['title']);
        self::assertSame('Explore a client workshop', $this->rooms->messages((int) $created['id'])[0]['content']);
    }

    public function test_ai_canvas_tool_applies_patch_immediately_and_records_history(): void
    {
        $previous = $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] ?? null;
        $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = 'true';
        try {
            $provider = $this->provider([
                new AssistantTurn('I added an idea to the canvas.', [new ToolCall('proposal-1', 'proposeCanvasPatch', [
                    'patch' => ['nodes' => [[
                        'clientId' => 'pilot', 'type' => 'idea', 'title' => 'Run a pilot',
                        'content' => 'Start small.', 'x' => 20, 'y' => 40,
                    ]]],
                    'inspirationCards' => [['title' => 'Perspective', 'text' => 'Invite one teammate.']],
                ])], 'tool_calls'),
                new AssistantTurn('You can restore an earlier canvas version from history.', [], 'completed'),
            ]);
            $chat = $this->chat($provider);
            $chat->startTurn($this->roomId, 'Suggest an idea');
            $chat->runTurn($this->roomId);

            self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
            self::assertSame(1, $this->rooms->find($this->roomId)['graph_version']);
            self::assertSame('Run a pilot', $this->connection->table('julianna_idea_graph_nodes')->first()->title);
            self::assertSame(0, $this->connection->table('julianna_idea_proposals')->count());
            self::assertContains('graph.updated', array_column($this->rooms->events($this->roomId, 0), 'event'));
            self::assertSame([], $this->rooms->pendingActions($this->roomId));
            self::assertSame('proposeCanvasPatch', $provider->requests[0]->tools[array_key_last($provider->requests[0]->tools)]->name);
            $toolResults = array_values(array_filter($this->rooms->messages($this->roomId), static fn (array $message): bool => $message['role'] === 'tool'));
            self::assertCount(1, $toolResults);
            self::assertFalse($toolResults[0]['metadata']['is_error']);
            self::assertStringContainsString('applied', strtolower($toolResults[0]['content']));
            self::assertStringContainsString('New node IDs created by this patch: #1, #2.', $toolResults[0]['content']);
            self::assertStringContainsString('not a pre-existing duplicate', $toolResults[0]['content']);
            self::assertSame(1, $provider->requests[1]->currentGraph['nodes'][0]['id']);
            self::assertSame(2, $provider->requests[1]->currentGraph['nodes'][1]['id']);
            self::assertSame($toolResults[0]['content'], $provider->requests[1]->messages[array_key_last($provider->requests[1]->messages)]['content']);
            self::assertCount(2, $this->connection->table('julianna_idea_history')->get());
        } finally {
            if ($previous === null) {
                unset($_ENV['JULIANNA_IDEA_GRAPH_ENABLED']);
            } else {
                $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = $previous;
            }
        }
    }

    public function test_malformed_ai_canvas_node_gets_feedback_then_corrected_patch_applies_once(): void
    {
        $previous = $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] ?? null;
        $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = 'true';
        try {
            $provider = $this->provider([
                new AssistantTurn('I have a canvas suggestion.', [new ToolCall('bad-proposal', 'proposeCanvasPatch', [
                    'patch' => ['nodes' => [[
                        'id' => 'concept-1', 'text' => 'Sensitive idea detail',
                        'type' => 'concept', 'title' => 'Initial idea',
                    ]]],
                ])], 'tool_calls'),
                new AssistantTurn('I corrected the format.', [new ToolCall('corrected-proposal', 'proposeCanvasPatch', [
                    'patch' => ['nodes' => [[
                        'clientId' => 'concept-1', 'type' => 'idea', 'title' => 'Initial idea',
                        'content' => 'Sensitive idea detail', 'x' => 0, 'y' => 0,
                    ]]],
                ])], 'tool_calls'),
                new AssistantTurn('The canvas has been updated.', [], 'completed'),
            ]);
            $chat = $this->chat($provider);
            $chat->startTurn($this->roomId, 'Suggest an idea for the empty canvas');
            $chat->runTurn($this->roomId);

            $messages = $this->rooms->messages($this->roomId);
            $toolResults = array_values(array_filter($messages, static fn (array $message): bool => $message['role'] === 'tool'));
            self::assertCount(2, $toolResults);
            self::assertTrue($toolResults[0]['metadata']['is_error']);
            self::assertStringContainsString('clientId', $toolResults[0]['content']);
            self::assertStringNotContainsString('Sensitive idea detail', $toolResults[0]['content']);
            self::assertFalse($toolResults[1]['metadata']['is_error']);
            self::assertSame($toolResults[0]['content'], $provider->requests[1]->messages[array_key_last($provider->requests[1]->messages)]['content']);
            self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
            self::assertSame(1, $this->rooms->find($this->roomId)['graph_version']);
            self::assertSame('Initial idea', $this->connection->table('julianna_idea_graph_nodes')->first()->title);
            self::assertSame(0, $this->connection->table('julianna_idea_proposals')->count());
            self::assertCount(2, $this->connection->table('julianna_idea_history')->get());
        } finally {
            if ($previous === null) {
                unset($_ENV['JULIANNA_IDEA_GRAPH_ENABLED']);
            } else {
                $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = $previous;
            }
        }
    }

    public function test_canvas_patch_does_not_overwrite_a_concurrent_graph_change(): void
    {
        $this->assertConcurrentCanvasPatchIsRejected(function (): void {
            $this->connection->table('julianna_idea_rooms')->where('id', $this->roomId)->increment('graph_version');
        }, 1, 0);
    }

    public function test_canvas_patch_does_not_overwrite_a_concurrent_plan_change(): void
    {
        $this->assertConcurrentCanvasPatchIsRejected(function (): void {
            $plan = Plan::empty();
            $plan['outcome'] = 'Someone else edited the plan';
            $this->rooms->updatePlan($this->roomId, $plan, 'active');
        }, 0, 1);
    }

    private function assertConcurrentCanvasPatchIsRejected(\Closure $concurrentChange, int $graphVersion, int $planVersion): void
    {
        $previous = $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] ?? null;
        $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = 'true';
        try {
            $provider = $this->provider([
                new AssistantTurn('I will add an idea.', [new ToolCall('stale-patch', 'proposeCanvasPatch', [
                    'patch' => ['nodes' => [[
                        'clientId' => 'late', 'type' => 'idea', 'title' => 'Late idea',
                        'content' => '', 'x' => 0, 'y' => 0,
                    ]]],
                ])], 'tool_calls'),
                new AssistantTurn('The room changed; I did not update the canvas.', [], 'completed'),
            ], $concurrentChange);
            $chat = $this->chat($provider);
            $chat->startTurn($this->roomId, 'Add an idea to the canvas');
            $chat->runTurn($this->roomId);

            $messages = $this->rooms->messages($this->roomId);
            $toolResults = array_values(array_filter($messages, static fn (array $message): bool => $message['role'] === 'tool'));
            self::assertCount(1, $toolResults);
            self::assertTrue($toolResults[0]['metadata']['is_error']);
            self::assertStringContainsString('room changed', strtolower($toolResults[0]['content']));
            self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
            self::assertSame($graphVersion, $this->rooms->find($this->roomId)['graph_version']);
            self::assertSame($planVersion, $this->rooms->find($this->roomId)['plan_version']);
            self::assertSame([], $this->connection->table('julianna_idea_graph_nodes')->get()->all());
            self::assertSame(0, $this->connection->table('julianna_idea_history')->count());
            self::assertSame(0, $this->connection->table('julianna_idea_proposals')->count());
        } finally {
            if ($previous === null) {
                unset($_ENV['JULIANNA_IDEA_GRAPH_ENABLED']);
            } else {
                $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] = $previous;
            }
        }
    }

    public function test_legacy_pending_write_becomes_stale_without_execution_or_duplicate_results(): void
    {
        $tickets = $this->createMock(Tickets::class);
        $tickets->expects(self::never())->method('quickAddTicket');
        $chat = $this->chat($this->provider([]), tickets: $tickets);
        $action = $this->rooms->addAction($this->roomId, 'old-call', 'addTask',
            ['projectId' => 2, 'headline' => 'Next step'], false);
        $this->rooms->setGeneration($this->roomId, 'awaiting_confirmation', resetTurns: true);

        $state = $chat->state($this->roomId);
        self::assertSame([], $state['pendingActions']);
        self::assertSame('stale', $state['staleActions'][0]['status']);
        self::assertSame('stale', $this->rooms->action($this->roomId, (int) $action['id'])['status']);
        self::assertSame('failed', $state['generation']['status']);
        self::assertSame(['tool'], array_column($this->rooms->messages($this->roomId), 'role'));
        self::assertTrue($this->rooms->messages($this->roomId)[0]['metadata']['is_error']);

        $chat->state($this->roomId);
        self::assertCount(1, $this->rooms->messages($this->roomId));
    }

    public function test_stale_approval_cannot_be_confirmed(): void
    {
        $tickets = $this->createMock(Tickets::class);
        $tickets->expects(self::never())->method('quickAddTicket');
        $chat = $this->chat($this->provider([]), tickets: $tickets);
        $action = $this->rooms->addAction($this->roomId, 'old-call', 'addTask',
            ['projectId' => 2, 'headline' => 'Not created'], false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Old action approvals are stale');
        try {
            $chat->resolveAction($this->roomId, (int) $action['id'], true);
        } finally {
            self::assertSame('stale', $this->rooms->action($this->roomId, (int) $action['id'])['status']);
            self::assertSame([], $this->rooms->pendingActions($this->roomId));
        }
    }

    public function test_stale_approval_can_only_be_discarded(): void
    {
        $tickets = $this->createMock(Tickets::class);
        $tickets->expects(self::never())->method('quickAddTicket');
        $chat = $this->chat($this->provider([]), tickets: $tickets);
        $action = $this->rooms->addAction($this->roomId, 'old-call', 'addTask',
            ['projectId' => 2, 'headline' => 'Not created'], false);

        $state = $chat->resolveAction($this->roomId, (int) $action['id'], false);

        self::assertSame([], $state['pendingActions']);
        self::assertSame([], $state['staleActions']);
        self::assertSame('discarded', $this->rooms->action($this->roomId, (int) $action['id'])['status']);
        self::assertCount(1, $this->rooms->messages($this->roomId));
    }

    public function test_initial_idea_can_start_without_duplicating_its_message(): void
    {
        $this->rooms->addMessage($this->roomId, 'user', 'An idea entered while creating the room');
        $provider = $this->provider([new AssistantTurn('Tell me more.', [], 'completed')]);
        $chat = $this->chat($provider);

        $chat->startTurn($this->roomId, '');
        $chat->runTurn($this->roomId);

        self::assertSame(['user', 'assistant'], array_column($this->rooms->messages($this->roomId), 'role'));
        self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
    }

    public function test_expired_legacy_approval_is_still_stale_and_not_executable(): void
    {
        $tickets = $this->createMock(Tickets::class);
        $tickets->expects(self::never())->method('quickAddTicket');
        $chat = $this->chat($this->provider([]), tickets: $tickets);
        $action = $this->rooms->addAction($this->roomId, 'old-call', 'addTask',
            ['projectId' => 2, 'headline' => 'Too late'], false);
        $this->connection->table('julianna_idea_actions')->where('id', $action['id'])
            ->update(['expires_at' => '2000-01-01 00:00:00']);

        $state = $chat->state($this->roomId);

        self::assertSame([], $state['pendingActions']);
        self::assertSame('stale', $this->rooms->action($this->roomId, (int) $action['id'])['status']);
        self::assertCount(1, $this->rooms->messages($this->roomId));
    }

    public function test_failed_turn_can_retry_without_repeating_the_user_message(): void
    {
        $provider = $this->provider([new LogicException('private provider detail'), new AssistantTurn('Recovered.', [], 'completed')]);
        $chat = $this->chat($provider);
        $chat->startTurn($this->roomId, 'Please respond');
        $chat->runTurn($this->roomId);
        self::assertSame('failed', $this->rooms->generation($this->roomId)['status']);
        self::assertSame(['user'], array_column($this->rooms->messages($this->roomId), 'role'));

        $chat->retryTurn($this->roomId);
        $chat->runTurn($this->roomId);
        self::assertSame('completed', $this->rooms->generation($this->roomId)['status']);
        self::assertSame(['user', 'assistant'], array_column($this->rooms->messages($this->roomId), 'role'));
    }

    /** @param list<AssistantTurn|LogicException> $turns */
    private function provider(array $turns, ?\Closure $beforeFirstResponse = null): AiProvider
    {
        return new class($turns, $beforeFirstResponse) implements AiProvider
        {
            /** @var list<ChatRequest> */
            public array $requests = [];

            public function __construct(private array $turns, private ?\Closure $beforeFirstResponse) {}

            public function respond(ChatRequest $request): ChatResponse
            {
                throw new LogicException('Legacy response not used.');
            }

            public function turn(ChatRequest $request): AssistantTurn
            {
                return $this->streamTurn($request, static function (): void {});
            }

            public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn
            {
                $firstResponse = $this->requests === [];
                $this->requests[] = $request;
                if ($firstResponse && $this->beforeFirstResponse !== null) {
                    ($this->beforeFirstResponse)();
                }
                $turn = array_shift($this->turns);
                if ($turn instanceof LogicException) {
                    throw $turn;
                }
                if (! $turn instanceof AssistantTurn) {
                    throw new LogicException('No fake provider response.');
                }
                if ($turn->text !== '') {
                    $onDelta($turn->text);
                }

                return $turn;
            }
        };
    }

    private function chat(AiProvider $provider, ?Projects $projects = null, ?Tickets $tickets = null): WorkspaceChat
    {
        $permissions = $this->createMock(PermissionService::class);
        $projects ??= $this->createMock(Projects::class);
        $tickets ??= $this->createMock(Tickets::class);
        $goals = $this->createMock(Goalcanvas::class);
        $calendar = $this->createMock(Calendar::class);
        $ideaRooms = new IdeaRoom($this->rooms, $permissions, $projects, $goals, $tickets);
        $registry = new IdeaRoomToolRegistry($permissions, $projects, $tickets, $goals, $calendar);

        $graph = new IdeaGraph($ideaRooms, new GraphRepository($this->connection), $this->rooms, $permissions);

        return new WorkspaceChat($ideaRooms, $this->rooms, $registry, $provider, $graph);
    }
}
