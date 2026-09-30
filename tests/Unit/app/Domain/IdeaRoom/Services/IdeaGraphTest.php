<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\IdeaRoom\Mcp\ConnectCanvasNodesTool;
use Leantime\Domain\IdeaRoom\Mcp\DisconnectCanvasNodesTool;
use Leantime\Domain\IdeaRoom\Mcp\EditCanvasNodeTool;
use Leantime\Domain\IdeaRoom\Mcp\RenameCanvasNodeTool;
use Leantime\Domain\IdeaRoom\Repositories\GraphRepository;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Leantime\Domain\IdeaRoom\Support\Plan;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use PDO;
use Unit\TestCase;

final class IdeaGraphTest extends TestCase
{
    private SQLiteConnection $db;

    private RoomRepository $rooms;

    private IdeaGraph $graph;

    private IdeaRoom $ideaRooms;

    private int $roomId;

    private bool $mayViewProject = false;

    protected function setUp(): void
    {
        parent::setUp();
        session()->put('userdata.id', 1);
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
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
        $schema->create('julianna_idea_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('event_name');
            $table->json('payload_json');
            $table->dateTime('created_at');
        });
        $this->rooms = new RoomRepository($this->db);
        $this->roomId = (int) $this->rooms->create(1, null, 'Canvas room', Plan::empty())['id'];
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('currentUserCan')->willReturnCallback(
            fn (string $permission, ?int $projectId): bool => $permission === ProjectsPermissions::VIEW && in_array($projectId, [99, 100], true) && $this->mayViewProject
        );
        $projects = $this->createMock(Projects::class);
        $projects->method('getProject')->willReturnCallback(static fn (int $projectId): array|bool => $projectId === 99 ? ['id' => 99, 'name' => 'Visible project'] : false);
        $this->ideaRooms = new IdeaRoom(
            $this->rooms,
            $permissions,
            $projects,
            $this->createMock(Goalcanvas::class),
            $this->createMock(Tickets::class),
        );
        $this->graph = new IdeaGraph($this->ideaRooms, new GraphRepository($this->db), $this->rooms, $permissions);
    }

    private function node(string $clientId, string $title): array
    {
        return ['clientId' => $clientId, 'type' => 'idea', 'title' => $title, 'content' => '', 'x' => 1, 'y' => 2, 'metadata' => []];
    }

    public function test_empty_graph_and_optimistic_version_conflict(): void
    {
        self::assertSame(['version' => 0, 'mode' => 'explore', 'nodes' => [], 'links' => []], $this->graph->graph($this->roomId)['graph']);
        $saved = $this->graph->save($this->roomId, 0, [$this->node('a', 'First')], []);
        self::assertSame(1, $saved['graph']['version']);
        self::assertSame('First', $saved['graph']['nodes'][0]['title']);
        $this->expectException(GraphConflictException::class);
        $this->graph->save($this->roomId, 0, [$this->node('a', 'Stale')], []);
    }

    public function test_graph_only_restore_does_not_advance_plan_version_when_json_keys_are_reordered(): void
    {
        $this->graph->save($this->roomId, 0, [$this->node('test', 'Temporary')], []);
        $baselineId = $this->graph->history($this->roomId)[1]['id'];
        $plan = Plan::empty();
        $this->db->table('julianna_idea_rooms')->where('id', $this->roomId)->update([
            'plan_json' => json_encode([
                'tasks' => $plan['tasks'], 'outcome' => $plan['outcome'],
                'milestones' => $plan['milestones'], 'assumptions' => $plan['assumptions'],
                'projectName' => $plan['projectName'], 'openQuestions' => $plan['openQuestions'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $restored = $this->graph->restoreHistory($this->roomId, $baselineId, 1, 0);
        self::assertSame(0, $restored['planVersion']);
        self::assertSame(0, $this->rooms->find($this->roomId)['plan_version']);
        self::assertSame([], $restored['graph']['nodes']);
    }

    public function test_ai_patch_applies_canvas_plan_and_mode_without_a_pending_proposal(): void
    {
        $result = $this->graph->applyPatch($this->roomId, [
            'nodes' => [$this->node('first', 'First step')],
            'plan' => ['outcome' => 'A clearer direction'],
            'mode' => 'execute',
        ], [], 'chat', 'Draft the direction', 0, 0);

        self::assertSame(1, $result['graph']['version']);
        self::assertSame('execute', $result['graph']['mode']);
        self::assertSame(['First step'], array_column($result['graph']['nodes'], 'title'));
        self::assertSame('A clearer direction', $result['plan']['outcome']);
        self::assertSame(1, $result['planVersion']);
        self::assertSame(0, $this->db->table('julianna_idea_proposals')->count());

        $history = $this->graph->history($this->roomId);
        self::assertCount(2, $history);
        self::assertSame([1, 0], array_column($history, 'graphVersion'));
        self::assertTrue($history[0]['isCurrent']);
        self::assertFalse($history[1]['isCurrent']);
        self::assertSame('ai', $history[0]['origin']);
        self::assertSame('Draft the direction', $history[0]['summary']);
        self::assertArrayNotHasKey('graph', $history[0]);
        self::assertSame($history[0]['id'], $result['historyEntry']['id']);
    }

    public function test_history_restore_round_trip_preserves_node_and_link_ids_plan_and_mode(): void
    {
        $first = $this->graph->applyPatch($this->roomId, [
            'nodes' => [$this->node('a', 'A'), $this->node('b', 'B')],
            'links' => [['clientId' => 'ab', 'sourceId' => 'a', 'targetId' => 'b', 'type' => 'leads_to']],
            'plan' => ['outcome' => 'First outcome'],
        ], [], 'chat', 'Initial idea', 0, 0);
        $firstEntryId = $first['historyEntry']['id'];
        $firstNodeIds = array_column($first['graph']['nodes'], 'id');
        $firstLinkId = $first['graph']['links'][0]['id'];

        $second = $this->graph->applyPatch($this->roomId, [
            'removeNodeIds' => [$firstNodeIds[0]],
            'plan' => ['outcome' => 'Second outcome'],
            'mode' => 'execute',
        ], [], 'chat', 'Narrow the idea', 1, 1);
        self::assertSame(['B'], array_column($second['graph']['nodes'], 'title'));
        self::assertSame([], $second['graph']['links']);

        $undone = $this->graph->restoreHistory($this->roomId, $firstEntryId, $second['graph']['version'], $second['planVersion']);
        self::assertSame(3, $undone['graph']['version']);
        self::assertSame('explore', $undone['graph']['mode']);
        self::assertSame($firstNodeIds, array_column($undone['graph']['nodes'], 'id'));
        self::assertSame([$firstLinkId], array_column($undone['graph']['links'], 'id'));
        self::assertSame('First outcome', $undone['plan']['outcome']);

        $redone = $this->graph->restoreHistory($this->roomId, $second['historyEntry']['id'], $undone['graph']['version'], $undone['planVersion']);
        self::assertSame(4, $redone['graph']['version']);
        self::assertSame('execute', $redone['graph']['mode']);
        self::assertSame(['B'], array_column($redone['graph']['nodes'], 'title'));
        self::assertSame('Second outcome', $redone['plan']['outcome']);
        self::assertCount(5, $this->graph->history($this->roomId));
    }

    public function test_restoring_current_history_is_noop_and_stale_retry_cannot_duplicate_a_revision(): void
    {
        $first = $this->graph->applyPatch($this->roomId, ['nodes' => [$this->node('a', 'A')]], [], 'chat', '', 0, 0);
        $current = $this->graph->restoreHistory($this->roomId, $first['historyEntry']['id'], 1, 0);
        self::assertSame(1, $current['graph']['version']);
        self::assertCount(2, $this->graph->history($this->roomId));

        $baselineId = $this->graph->history($this->roomId)[1]['id'];
        $restored = $this->graph->restoreHistory($this->roomId, $baselineId, 1, 0);
        self::assertSame(2, $restored['graph']['version']);
        self::assertSame([], $restored['graph']['nodes']);
        self::assertCount(3, $this->graph->history($this->roomId));
        try {
            $this->graph->restoreHistory($this->roomId, $baselineId, 1, 0);
            self::fail('A stale retry created a duplicate history entry.');
        } catch (GraphConflictException) {
            self::assertCount(3, $this->graph->history($this->roomId));
        }
    }

    public function test_plan_only_patch_is_immediate_versioned_and_rejects_stale_retries(): void
    {
        $applied = $this->graph->applyPatch($this->roomId, ['plan' => ['outcome' => 'Pilot outcome']], [], 'chat', '', 0, 0);
        self::assertSame(0, $applied['graph']['version']);
        self::assertSame(1, $applied['planVersion']);
        self::assertSame('Pilot outcome', $this->rooms->find($this->roomId)['plan']['outcome']);
        self::assertSame([1, 0], array_column($this->graph->history($this->roomId), 'planVersion'));
        self::assertCount(2, $this->graph->history($this->roomId));

        try {
            $this->graph->applyPatch($this->roomId, ['plan' => ['outcome' => 'Duplicate']], [], 'chat', '', 0, 0);
            self::fail('A stale plan patch was accepted.');
        } catch (GraphConflictException) {
            self::assertCount(2, $this->graph->history($this->roomId));
            self::assertSame('Pilot outcome', $this->rooms->find($this->roomId)['plan']['outcome']);
        }

        $baselineId = $this->graph->history($this->roomId)[1]['id'];
        $restored = $this->graph->restoreHistory($this->roomId, $baselineId, 0, 1);
        self::assertSame('', $restored['plan']['outcome']);
        self::assertSame(2, $restored['planVersion']);
        self::assertCount(3, $this->graph->history($this->roomId));
    }

    public function test_inspiration_cards_only_apply_as_insight_nodes_with_history(): void
    {
        $applied = $this->graph->applyPatch($this->roomId, [], [
            ['title' => 'Another angle', 'text' => 'Invite a colleague to critique the idea.'],
        ], 'chat', '', 0, 0);

        self::assertSame(1, $applied['graph']['version']);
        self::assertSame(['insight'], array_column($applied['graph']['nodes'], 'type'));
        self::assertSame(['Another angle'], array_column($applied['graph']['nodes'], 'title'));
        self::assertSame(0, $this->db->table('julianna_idea_proposals')->count());
        self::assertCount(2, $this->graph->history($this->roomId));
    }

    public function test_history_read_and_restore_are_room_scoped_and_authorized(): void
    {
        $first = $this->graph->applyPatch($this->roomId, ['nodes' => [$this->node('a', 'Private')]], [], 'chat', '', 0, 0);
        $otherRoomId = (int) $this->rooms->create(1, null, 'Other room', Plan::empty())['id'];
        self::assertSame([], $this->graph->history($otherRoomId));
        try {
            $this->graph->restoreHistory($otherRoomId, $first['historyEntry']['id'], 0, 0);
            self::fail('A history entry from another room was accepted.');
        } catch (NotFoundException) {
            self::assertSame(0, $this->rooms->find($otherRoomId)['graph_version']);
        }
        session()->put('userdata.id', 2);
        $readDenied = null;
        try {
            $this->graph->history($this->roomId);
        } catch (AuthorizationException $exception) {
            $readDenied = $exception;
        }
        self::assertInstanceOf(AuthorizationException::class, $readDenied);
        $this->expectException(AuthorizationException::class);
        $this->graph->restoreHistory($this->roomId, $first['historyEntry']['id'], 1, 0);
    }

    public function test_link_only_removal_can_be_restored_from_history_with_original_id(): void
    {
        $initial = $this->graph->applyPatch($this->roomId, [
            'nodes' => [$this->node('a', 'A'), $this->node('b', 'B')],
            'links' => [['clientId' => 'ab', 'sourceId' => 'a', 'targetId' => 'b', 'type' => 'supports']],
        ], [], 'chat', '', 0, 0);
        $linkId = $initial['graph']['links'][0]['id'];
        $withoutLink = $this->graph->applyPatch($this->roomId, ['removeLinkIds' => [$linkId]], [], 'chat', '', 1, 0);
        self::assertSame([], $withoutLink['graph']['links']);

        $restored = $this->graph->restoreHistory($this->roomId, $initial['historyEntry']['id'], 2, 0);
        self::assertSame([$linkId], array_column($restored['graph']['links'], 'id'));
        self::assertSame(array_column($initial['graph']['nodes'], 'id'), array_column($restored['graph']['nodes'], 'id'));
        self::assertSame(3, $restored['graph']['version']);
    }

    public function test_manual_save_and_mode_changes_are_in_the_same_history(): void
    {
        $saved = $this->graph->save($this->roomId, 0, [$this->node('a', 'Manual idea')], []);
        self::assertSame(1, $saved['graph']['version']);
        $mode = $this->graph->mode($this->roomId, 'execute', 1);
        self::assertSame(2, $mode['graph']['version']);
        self::assertSame([2, 1, 0], array_column($this->graph->history($this->roomId), 'graphVersion'));
    }

    public function test_manual_plan_edit_is_recorded_and_can_be_restored(): void
    {
        $plan = Plan::empty();
        $plan['outcome'] = 'Manually edited outcome';
        $saved = $this->graph->replacePlan($this->roomId, $plan);
        self::assertSame('Manually edited outcome', $saved['plan']['outcome']);
        self::assertSame(1, $saved['planVersion']);
        self::assertSame([1, 0], array_column($this->graph->history($this->roomId), 'planVersion'));

        $baselineId = $this->graph->history($this->roomId)[1]['id'];
        $restored = $this->graph->restoreHistory($this->roomId, $baselineId, 0, 1);
        self::assertSame('', $restored['plan']['outcome']);
        self::assertSame(2, $restored['planVersion']);
    }

    public function test_history_restore_does_not_delete_a_node_hidden_after_permission_revocation(): void
    {
        $this->mayViewProject = true;
        $projectNode = $this->node('project', 'Protected project reference');
        $projectNode['metadata'] = ['reference' => ['type' => 'project', 'id' => 99]];
        $this->graph->applyPatch($this->roomId, ['nodes' => [$projectNode]], [], 'chat', '', 0, 0);
        $this->graph->applyPatch($this->roomId, ['nodes' => [$this->node('ordinary', 'Ordinary idea')]], [], 'chat', '', 1, 0);
        $baselineId = $this->graph->history($this->roomId)[2]['id'];
        $this->mayViewProject = false;

        try {
            $this->graph->restoreHistory($this->roomId, $baselineId, 2, 0);
        } catch (AuthorizationException) {
            // Rejecting the restore is safe when a hidden reference would be affected.
        }

        self::assertNotNull($this->db->table('julianna_idea_graph_nodes')->where('title', 'Protected project reference')->whereNull('deleted_at')->first());
    }

    public function test_new_node_client_ids_resolve_into_typed_links(): void
    {
        $saved = $this->graph->save($this->roomId, 0, [$this->node('a', 'A'), $this->node('b', 'B')], [
            ['clientId' => 'edge', 'sourceId' => 'a', 'targetId' => 'b', 'type' => 'supports'],
        ]);
        self::assertCount(2, $saved['graph']['nodes']);
        self::assertSame('supports', $saved['graph']['links'][0]['type']);
        self::assertSame($saved['graph']['nodes'][0]['id'], $saved['graph']['links'][0]['sourceId']);
    }

    public function test_stale_client_can_reload_and_retry_with_new_version(): void
    {
        $this->graph->save($this->roomId, 0, [$this->node('a', 'First')], []);
        try {
            $this->graph->save($this->roomId, 0, [$this->node('a', 'Stale')], []);
            self::fail('A stale write was accepted.');
        } catch (GraphConflictException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        $fresh = $this->graph->graph($this->roomId)['graph'];
        $fresh['nodes'][0]['title'] = 'Retried';
        self::assertSame(2, $this->graph->save($this->roomId, $fresh['version'], $fresh['nodes'], $fresh['links'])['graph']['version']);
    }

    public function test_cross_room_reference_and_incident_link_are_redacted_after_access_revocation(): void
    {
        $target = $this->rooms->create(1, null, 'Another room', Plan::empty());
        $reference = $this->node('reference', 'Private room');
        $reference['metadata'] = ['reference' => ['type' => 'room', 'id' => (int) $target['id']]];
        $this->graph->save($this->roomId, 0, [$reference, $this->node('ordinary', 'Visible idea')], [
            ['clientId' => 'edge', 'sourceId' => 'ordinary', 'targetId' => 'reference', 'type' => 'related_to'],
        ]);
        $this->db->table('julianna_idea_rooms')->where('id', $target['id'])->update(['owner_user_id' => 2]);

        $visible = $this->graph->graph($this->roomId)['graph'];
        self::assertSame(['Visible idea'], array_column($visible['nodes'], 'title'));
        self::assertSame([], $visible['links']);
    }

    public function test_project_references_are_redacted_and_cannot_be_edited_without_access(): void
    {
        $this->mayViewProject = true;
        $referenced = $this->node('project', 'Sensitive project');
        $referenced['metadata'] = ['reference' => ['type' => 'project', 'id' => 99]];
        $this->graph->save($this->roomId, 0, [$referenced, $this->node('normal', 'Visible')], []);
        $this->mayViewProject = false;
        $visible = $this->graph->graph($this->roomId)['graph'];
        self::assertSame(['Visible'], array_column($visible['nodes'], 'title'));
        $updated = $this->graph->save($this->roomId, 1, $visible['nodes'], []);
        self::assertCount(1, $updated['graph']['nodes']);
        self::assertCount(2, $this->db->table('julianna_idea_graph_nodes')->get());
        $this->expectException(AuthorizationException::class);
        $this->graph->save($this->roomId, 2, [$referenced], []);
    }

    public function test_mcp_proposal_rejects_nonexistent_project_reference_even_with_view_permission(): void
    {
        $this->mayViewProject = true;
        $node = $this->node('missing-project', 'Unavailable project');
        $node['metadata'] = ['reference' => ['type' => 'project', 'id' => 100]];
        $this->expectException(AuthorizationException::class);
        $this->graph->createMcpProposal($this->roomId, 0, ['nodes' => [$node]]);
    }

    public function test_research_citation_is_not_a_node_until_explicitly_kept(): void
    {
        $source = $this->graph->storeSources($this->roomId, [[
            'url' => 'https://example.com/research', 'title' => 'Useful research', 'snippet' => 'A summary',
            'provider' => 'google', 'query' => 'topic',
        ]])[0];
        self::assertSame([], $this->graph->graph($this->roomId)['graph']['nodes']);
        $kept = $this->graph->keepSource($this->roomId, (int) $source['id'], 0);
        self::assertSame('source', $kept['graph']['nodes'][0]['type']);
        self::assertSame($source['id'], $kept['graph']['nodes'][0]['metadata']['sourceId']);
        self::assertSame(1, $kept['graph']['version']);
    }

    public function test_proposal_requires_review_and_acceptance_merges_plan_once(): void
    {
        $proposal = $this->graph->createProposal($this->roomId, [
            'nodes' => [$this->node('proposal-a', 'An insight')],
            'plan' => ['outcome' => 'Explore options'],
        ], [['title' => 'Think bigger', 'text' => 'Consider alternatives']]);
        self::assertSame('pending', $proposal['status']);
        self::assertSame([], $this->graph->graph($this->roomId)['graph']['nodes']);
        $accepted = $this->graph->acceptProposal($this->roomId, (int) $proposal['id'], 0);
        self::assertSame('accepted', $accepted['proposal']['status']);
        self::assertSame('An insight', $accepted['graph']['nodes'][0]['title']);
        self::assertSame('Explore options', $this->rooms->find($this->roomId)['plan']['outcome']);
        $this->expectException(GraphConflictException::class);
        $this->graph->acceptProposal($this->roomId, (int) $proposal['id'], 1);
    }

    public function test_proposed_node_removal_also_removes_incident_links(): void
    {
        $saved = $this->graph->save($this->roomId, 0, [$this->node('a', 'A'), $this->node('b', 'B')], [
            ['clientId' => 'edge', 'sourceId' => 'a', 'targetId' => 'b', 'type' => 'leads_to'],
        ])['graph'];
        $proposal = $this->graph->createProposal($this->roomId, [
            'removeNodeIds' => [$saved['nodes'][0]['id']],
        ]);
        $accepted = $this->graph->acceptProposal($this->roomId, $proposal['id'], $saved['version']);
        self::assertSame(['B'], array_column($accepted['graph']['nodes'], 'title'));
        self::assertSame([], $accepted['graph']['links']);
    }

    public function test_mcp_node_proposal_is_reviewable_and_rejects_stale_versions(): void
    {
        $proposal = $this->graph->createMcpProposal($this->roomId, 0, [
            'nodes' => [$this->node('mcp-node', 'A new idea')],
        ], [], 'Add a new idea');
        self::assertSame('mcp', $proposal['origin']);
        self::assertSame('Add a new idea', $proposal['summary']);
        self::assertSame(0, $proposal['graph_version']);
        self::assertSame([], $this->graph->graph($this->roomId)['graph']['nodes']);

        $accepted = $this->graph->acceptProposal($this->roomId, $proposal['id'], 0);
        self::assertSame(['A new idea'], array_column($accepted['graph']['nodes'], 'title'));
        self::assertSame('accepted', $accepted['proposal']['status']);

        $this->expectException(GraphConflictException::class);
        $this->graph->createMcpProposal($this->roomId, 0, ['nodes' => [$this->node('stale', 'Stale')]]);
    }

    public function test_deleted_nodes_and_incident_links_restore_with_original_ids(): void
    {
        $initial = $this->graph->save($this->roomId, 0, [$this->node('a', 'A'), $this->node('b', 'B')], [
            ['clientId' => 'ab', 'sourceId' => 'a', 'targetId' => 'b', 'type' => 'leads_to'],
        ])['graph'];
        $nodeId = $initial['nodes'][0]['id'];
        $linkId = $initial['links'][0]['id'];
        $remove = $this->graph->createMcpProposal($this->roomId, 1, ['removeNodeIds' => [$nodeId]]);
        self::assertCount(2, $this->graph->graph($this->roomId)['graph']['nodes']);
        $deleted = $this->graph->acceptProposal($this->roomId, $remove['id'], 1)['graph'];
        self::assertSame(['B'], array_column($deleted['nodes'], 'title'));
        self::assertSame([], $deleted['links']);
        self::assertSame([$nodeId], array_column($this->graph->recoverableNodes($this->roomId), 'id'));

        $restore = $this->graph->createMcpProposal($this->roomId, 2, ['restoreNodeIds' => [$nodeId]]);
        self::assertSame(['B'], array_column($this->graph->graph($this->roomId)['graph']['nodes'], 'title'));
        $restored = $this->graph->acceptProposal($this->roomId, $restore['id'], 2)['graph'];
        self::assertSame([$initial['nodes'][0]['id'], $initial['nodes'][1]['id']], array_column($restored['nodes'], 'id'));
        self::assertSame([$linkId], array_column($restored['links'], 'id'));
    }

    public function test_plan_proposal_checks_plan_version_and_accepts_without_canvas_write(): void
    {
        $proposal = $this->graph->proposePlanPatch($this->roomId, 0, 0, ['outcome' => 'A tested plan']);
        self::assertSame('mcp', $proposal['origin']);
        self::assertSame(0, $proposal['plan_version']);
        self::assertSame('', $this->rooms->find($this->roomId)['plan']['outcome']);
        $accepted = $this->graph->acceptProposal($this->roomId, $proposal['id'], 0);
        self::assertSame(0, $accepted['graph']['version']);
        self::assertSame('A tested plan', $this->rooms->find($this->roomId)['plan']['outcome']);
        self::assertSame(1, $this->rooms->find($this->roomId)['plan_version']);

        $stale = $this->graph->proposePlanPatch($this->roomId, 0, 1, ['outcome' => 'Stale']);
        $this->rooms->updatePlan($this->roomId, Plan::empty(), 'active');
        $this->expectException(GraphConflictException::class);
        $this->graph->acceptProposal($this->roomId, $stale['id'], 0);
    }

    public function test_mode_proposal_changes_mode_only_after_review(): void
    {
        $proposal = $this->graph->createMcpProposal($this->roomId, 0, ['mode' => 'execute']);
        self::assertSame('explore', $this->graph->graph($this->roomId)['graph']['mode']);
        $accepted = $this->graph->acceptProposal($this->roomId, $proposal['id'], 0);
        self::assertSame('execute', $accepted['graph']['mode']);
        self::assertSame(1, $accepted['graph']['version']);
    }

    public function test_mcp_edit_and_link_tools_apply_changes_immediately_with_history(): void
    {
        $initial = $this->graph->save($this->roomId, 0, [$this->node('a', 'A'), $this->node('b', 'B')], [])['graph'];
        $firstId = $initial['nodes'][0]['id'];
        $secondId = $initial['nodes'][1]['id'];

        $rename = (new RenameCanvasNodeTool($this->graph, $this->ideaRooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 1, 'nodeId' => $firstId, 'title' => 'Renamed',
        ])->toArray();
        self::assertFalse($rename['isError']);
        self::assertSame('applied', $rename['structuredContent']['status']);
        $renamed = $this->graph->graph($this->roomId)['graph'];
        self::assertSame('Renamed', $renamed['nodes'][0]['title']);
        self::assertSame(2, $renamed['version']);
        self::assertSame($rename['structuredContent']['historyEntry']['id'], $this->graph->history($this->roomId)[0]['id']);

        $edit = (new EditCanvasNodeTool($this->graph, $this->ideaRooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 2, 'nodeId' => $firstId, 'content' => 'Edited details',
        ])->toArray();
        self::assertFalse($edit['isError']);
        self::assertSame('applied', $edit['structuredContent']['status']);
        $edited = $this->graph->graph($this->roomId)['graph'];
        self::assertSame('Edited details', $edited['nodes'][0]['content']);
        self::assertSame(3, $edited['version']);

        $connect = (new ConnectCanvasNodesTool($this->graph, $this->ideaRooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 3,
            'sourceId' => $firstId, 'targetId' => $secondId, 'type' => 'supports',
        ])->toArray();
        self::assertFalse($connect['isError']);
        self::assertSame('applied', $connect['structuredContent']['status']);
        $linked = $this->graph->graph($this->roomId)['graph'];
        self::assertSame('supports', $linked['links'][0]['type']);
        self::assertSame(4, $linked['version']);

        $disconnect = (new DisconnectCanvasNodesTool($this->graph, $this->ideaRooms))->handle([
            'roomId' => $this->roomId, 'expectedVersion' => 4, 'linkId' => $linked['links'][0]['id'],
        ])->toArray();
        self::assertFalse($disconnect['isError']);
        self::assertSame('applied', $disconnect['structuredContent']['status']);
        $unlinked = $this->graph->graph($this->roomId)['graph'];
        self::assertSame([], $unlinked['links']);
        self::assertSame(5, $unlinked['version']);
        self::assertSame([], $this->graph->proposals($this->roomId));
        self::assertCount(6, $this->graph->history($this->roomId));
    }

    public function test_mcp_tool_rejects_unknown_fields_and_stale_version_without_a_proposal(): void
    {
        $tool = new RenameCanvasNodeTool($this->graph, $this->ideaRooms);
        $unknown = $tool->handle(['roomId' => $this->roomId, 'expectedVersion' => 0, 'nodeId' => 1, 'title' => 'No', 'unexpected' => true])->toArray();
        self::assertTrue($unknown['isError']);
        self::assertSame('invalid_request', $unknown['structuredContent']['code']);

        $initial = $this->graph->save($this->roomId, 0, [$this->node('a', 'A')], [])['graph'];
        $stale = $tool->handle(['roomId' => $this->roomId, 'expectedVersion' => 0, 'nodeId' => $initial['nodes'][0]['id'], 'title' => 'No'])->toArray();
        self::assertTrue($stale['isError']);
        self::assertSame('stale_version', $stale['structuredContent']['code']);
        self::assertSame([], $this->graph->proposals($this->roomId));
    }
}
