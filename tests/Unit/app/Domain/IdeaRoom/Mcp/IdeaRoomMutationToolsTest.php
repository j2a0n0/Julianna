<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Mcp;

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
use Leantime\Domain\IdeaRoom\Mcp\IdeaRoomMcpToolProvider;
use Leantime\Domain\IdeaRoom\Mcp\Tools\CreateIdeaRoomTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\ListIdeaRoomCanvasHistoryTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\ProposeIdeaRoomPlanTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\RequestIdeaRoomApprovalTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\RestoreIdeaRoomCanvasHistoryTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\SendIdeaRoomMessageTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\SetIdeaRoomModeTool;
use Leantime\Domain\IdeaRoom\Mcp\Tools\UpdateIdeaRoomContextTool;
use Leantime\Domain\IdeaRoom\Repositories\GraphRepository;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\IdeaRoom\Services\WorkspaceChat;
use Leantime\Domain\IdeaRoom\Support\Plan;
use Leantime\Domain\IdeaRoom\Tools\IdeaRoomToolRegistry;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use LogicException;
use PDO;
use Unit\TestCase;

final class IdeaRoomMutationToolsTest extends TestCase
{
    private SQLiteConnection $db;

    private RoomRepository $repository;

    private IdeaRoom $rooms;

    private IdeaGraph $graph;

    private WorkspaceChat $chat;

    private QueueIdeaRoomProvider $provider;

    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        session()->put('userdata.id', 1);
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $this->createSchema();
        $this->repository = new RoomRepository($this->db);
        $this->roomId = (int) $this->repository->create(1, null, 'Test room', Plan::empty())['id'];

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('currentUserCan')->willReturn(true);
        $projects = $this->createMock(Projects::class);
        $projects->method('getProject')->willReturnCallback(
            static fn (int $id): array|false => $id === 9 ? ['id' => 9, 'name' => 'Existing project'] : false
        );
        $projects->expects(self::never())->method('addProject');
        $goals = $this->createMock(Goalcanvas::class);
        $goals->expects(self::never())->method('createGoalboard');
        $tickets = $this->createMock(Tickets::class);
        $tickets->expects(self::never())->method('quickAddTicket');
        $calendar = $this->createMock(Calendar::class);

        $this->rooms = new IdeaRoom($this->repository, $permissions, $projects, $goals, $tickets);
        $this->graph = new IdeaGraph($this->rooms, new GraphRepository($this->db), $this->repository, $permissions);
        $this->provider = new QueueIdeaRoomProvider;
        $registry = new IdeaRoomToolRegistry($permissions, $projects, $tickets, $goals, $calendar);
        $this->chat = new WorkspaceChat($this->rooms, $this->repository, $registry, $this->provider, $this->graph);
    }

    public function test_create_room_returns_a_structured_result_and_stores_the_initial_idea(): void
    {
        $data = self::success((new CreateIdeaRoomTool($this->rooms))->handle(['idea' => 'Explore a pilot']));

        self::assertSame('/idea-room/'.$data['room']['id'], $data['url']);
        self::assertSame('Explore a pilot', $data['room']['title']);
        self::assertSame(1, $data['room']['owner_user_id']);
        self::assertSame('Explore a pilot', $this->repository->messages((int) $data['room']['id'])[0]['content']);
        self::assertSame(2, $this->db->table('julianna_idea_rooms')->count());
    }

    public function test_legacy_mcp_provider_exposes_no_retired_idea_room_tools(): void
    {
        self::assertSame([], IdeaRoomMcpToolProvider::toolClasses());
        self::assertSame(0, $this->db->table('julianna_idea_actions')->count());
    }

    public function test_mode_and_plan_tools_apply_immediately_and_record_history(): void
    {
        $mode = self::success((new SetIdeaRoomModeTool($this->graph, $this->rooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'mode' => 'execute',
        ]));
        self::assertSame('applied', $mode['status']);
        self::assertSame('mcp', $mode['historyEntry']['origin']);
        self::assertSame('execute', $mode['graph']['mode']);
        self::assertSame(1, $mode['graph']['version']);
        self::assertSame('execute', $this->graph->graph($this->roomId)['graph']['mode']);

        $patch = self::readyPlanPatch();
        $plan = self::success((new ProposeIdeaRoomPlanTool($this->graph, $this->rooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 1, 'expectedPlanVersion' => 0,
            'planPatch' => $patch,
        ]));
        self::assertSame('applied', $plan['status']);
        self::assertSame('mcp', $plan['historyEntry']['origin']);
        self::assertSame(1, $plan['planVersion']);
        $saved = $this->repository->find($this->roomId);
        self::assertSame('Pilot project', $saved['plan']['projectName']);
        self::assertSame('ready_for_review', $saved['status']);
        self::assertSame(1, $saved['plan_version']);
        self::assertCount(3, $this->graph->history($this->roomId));
        self::assertSame(0, $this->db->table('julianna_idea_proposals')->count());
    }

    public function test_context_change_checks_version_and_project_access(): void
    {
        $tool = new UpdateIdeaRoomContextTool($this->rooms);
        $updated = self::success($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'projectId' => 9,
        ]));
        self::assertSame(9, (int) $updated['room']['project_id']);
        self::assertSame(9, (int) $this->repository->find($this->roomId)['project_id']);

        self::error($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'projectId' => 10,
        ]), 'not_found_or_forbidden');
        self::assertSame(9, (int) $this->repository->find($this->roomId)['project_id']);

        $this->db->table('julianna_idea_rooms')->where('id', $this->roomId)->update(['graph_version' => 1]);
        self::error($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'projectId' => null,
        ]), 'stale_version');
        self::assertSame(9, (int) $this->repository->find($this->roomId)['project_id']);
    }

    public function test_approval_request_only_hands_complete_accepted_plan_to_web_user(): void
    {
        $tool = new RequestIdeaRoomApprovalTool($this->rooms);
        self::error($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'expectedPlanVersion' => 0,
        ]), 'invalid_request');

        self::success((new ProposeIdeaRoomPlanTool($this->graph, $this->rooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'expectedPlanVersion' => 0,
            'planPatch' => self::readyPlanPatch(),
        ]));
        self::error($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'expectedPlanVersion' => 0,
        ]), 'stale_version');
        $handoff = self::success($tool->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'expectedPlanVersion' => 1,
        ]));
        self::assertSame('pending_user_review', $handoff['status']);
        self::assertFalse($handoff['workspaceRecordsCreated']);
        self::assertTrue($handoff['canCallerApproveInWeb']);
        self::assertSame('/idea-room/'.$this->roomId, $handoff['url']);
        $room = $this->repository->find($this->roomId);
        self::assertSame('ready_for_review', $room['status']);
        self::assertNull($room['approvalResult']);
    }

    public function test_history_tools_list_and_restore_without_proposals(): void
    {
        $mode = self::success((new SetIdeaRoomModeTool($this->graph, $this->rooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 0, 'mode' => 'execute',
        ]));
        $baselineId = (int) $this->graph->history($this->roomId)[1]['id'];
        $list = self::success((new ListIdeaRoomCanvasHistoryTool($this->graph))->handle(['roomId' => $this->roomId]));
        self::assertCount(2, $list['history']);
        self::assertSame((int) $mode['historyEntry']['id'], (int) $list['history'][0]['id']);
        self::assertArrayNotHasKey('graph', $list['history'][0]);
        self::assertArrayNotHasKey('plan', $list['history'][0]);

        $restored = self::success((new RestoreIdeaRoomCanvasHistoryTool($this->graph))->handle([
            'roomId' => $this->roomId, 'historyEntryId' => $baselineId,
            'expectedVersion' => 1, 'expectedPlanVersion' => 0,
        ]));
        self::assertSame('restored', $restored['status']);
        self::assertSame('explore', $restored['graph']['mode']);
        self::assertCount(3, $this->graph->history($this->roomId));
        self::assertSame(0, $this->db->table('julianna_idea_proposals')->count());

        self::error((new RestoreIdeaRoomCanvasHistoryTool($this->graph))->handle([
            'roomId' => $this->roomId, 'historyEntryId' => $baselineId,
            'expectedVersion' => 1, 'expectedPlanVersion' => 0,
        ]), 'stale_version');
    }

    public function test_all_six_tools_return_structured_errors_for_invalid_arguments(): void
    {
        $cases = [
            [new CreateIdeaRoomTool($this->rooms), ['idea' => 'Valid', 'unexpected' => true]],
            [new SendIdeaRoomMessageTool($this->chat), ['roomId' => $this->roomId, 'expectedVersion' => -1, 'content' => 'Hi']],
            [new SetIdeaRoomModeTool($this->graph, $this->rooms), ['roomId' => $this->roomId, 'expectedVersion' => 0, 'mode' => 'invalid']],
            [new UpdateIdeaRoomContextTool($this->rooms), ['roomId' => $this->roomId, 'expectedVersion' => 0, 'projectId' => -1]],
            [new ProposeIdeaRoomPlanTool($this->graph, $this->rooms), ['roomId' => $this->roomId, 'expectedVersion' => 0, 'expectedPlanVersion' => 0, 'planPatch' => []]],
            [new RequestIdeaRoomApprovalTool($this->rooms), ['roomId' => $this->roomId, 'expectedVersion' => 0]],
        ];
        foreach ($cases as [$tool, $arguments]) {
            self::error($tool->handle($arguments), 'invalid_request');
        }
        self::assertSame(1, $this->db->table('julianna_idea_rooms')->count());
        self::assertSame(0, $this->db->table('julianna_idea_proposals')->count());
        self::assertSame(0, $this->db->table('julianna_idea_messages')->count());
    }

    private static function readyPlanPatch(): array
    {
        return [
            'projectName' => 'Pilot project',
            'outcome' => 'Deliver a useful pilot',
            'milestones' => [[
                'title' => 'Pilot milestone',
                'tasks' => [['title' => 'Run the pilot']],
            ]],
        ];
    }

    private static function success(ToolResult $result): array
    {
        $payload = $result->toArray();
        self::assertFalse($payload['isError']);
        self::assertSame('text', $payload['content'][0]['type']);
        self::assertNotSame('', $payload['content'][0]['text']);

        return $payload['structuredContent'];
    }

    private static function error(ToolResult $result, string $code): void
    {
        $payload = $result->toArray();
        self::assertTrue($payload['isError']);
        self::assertSame($code, $payload['structuredContent']['code']);
        self::assertNotSame('', $payload['content'][0]['text']);
    }

    private function createSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();
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
    }
}

final class QueueIdeaRoomProvider implements AiProvider
{
    /** @var list<AssistantTurn> */
    public array $turns = [];

    public function respond(ChatRequest $request): ChatResponse
    {
        throw new LogicException('Legacy response is not used.');
    }

    public function turn(ChatRequest $request): AssistantTurn
    {
        return $this->streamTurn($request, static function (): void {});
    }

    public function streamTurn(ChatRequest $request, callable $onDelta): AssistantTurn
    {
        $turn = array_shift($this->turns);
        if (! $turn instanceof AssistantTurn) {
            throw new LogicException('No queued assistant turn.');
        }
        if ($turn->text !== '') {
            $onDelta($turn->text);
        }

        return $turn;
    }
}
