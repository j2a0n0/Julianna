<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Auth\Services\UserSessionBuilder;
use Leantime\Domain\Blueprints\Services\Blueprints;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\Tickets\Services\Tickets;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use Leantime\Domain\Whiteboards\Support\WhiteboardConflictException;

/**
 * All tool transports cross this same live-authorization boundary. The model
 * never supplies an actor ID or a PHP class. Queued work temporarily receives
 * canonical userdata from current DB rows and always restores the old context.
 */
final class ToolDispatcher
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly SchemaValidator $validator,
        private readonly PlatformToolRegistry $platform,
        private readonly PermissionService $permissions,
        private readonly ConnectionInterface $db,
        private readonly AccountRepository $accounts,
        private readonly Tickets $tickets,
        private readonly Goalcanvas $goals,
        private readonly Whiteboards $whiteboards,
    ) {}

    /**
     * Validate identity, schema, scope and permission without producing a side effect.
     * The in-app agent uses this for communication draft creation.
     *
     * @param array<string, mixed> $arguments
     * @return array{definition:array<string,mixed>,arguments:array<string,mixed>,project_id:?int,project_ids:list<int>}
     */
    public function authorize(string $name, array $arguments, int $actorUserId, ?int $selectedProjectId = null, string $source = 'interactive'): array
    {
        return $this->withActor($actorUserId, $source, fn (): array => $this->authorizeCurrent($name, $arguments, $actorUserId, $selectedProjectId, $source));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{ok:bool,text:string,data?:array<string,mixed>}
     */
    public function dispatch(string $name, array $arguments, int $actorUserId, ?int $selectedProjectId = null, string $source = 'interactive'): array
    {
        return $this->withActor($actorUserId, $source, function () use ($name, $arguments, $actorUserId, $selectedProjectId, $source): array {
            $checked = $this->authorizeCurrent($name, $arguments, $actorUserId, $selectedProjectId, $source);
            $effect = $checked['definition']['effect'];
            if ($effect === 'communication' && ! in_array($source, ['publish', 'mcp'], true)) {
                throw new AuthorizationException('Communication must be reviewed as a draft.');
            }
            if ($this->catalog->isPlatformTool($name)) {
                // The old adapter's confirmation boolean is an internal implementation
                // detail. Authorization has already happened in this dispatcher and
                // the adapter itself rechecks entity permissions immediately below.
                return $this->platform->execute($name, $checked['arguments'], true);
            }

            if (in_array($name, ['searchWeb', 'researchWeb'], true)) {
                $rateLimiter = app()->make(RateLimiter::class);
                $rateKey = 'julianna-web-search:user:'.$actorUserId;
                if ($rateLimiter->tooManyAttempts($rateKey, 20)) {
                    throw new \RuntimeException('Web search limit reached. Please try again in an hour.');
                }
                $rateLimiter->hit($rateKey, 3600);
                $web = app()->make(WebSearch::class);
                $data = $name === 'searchWeb'
                    ? $web->search($checked['arguments']['query'])
                    : $web->research($checked['arguments']['query']);
                return ['ok' => true, 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'Web search completed.', 'data' => $data];
            }

            if (in_array($name, ['listSwotBlueprints', 'getSwotBlueprint', 'createSwotBlueprint', 'addSwotItems'], true)) {
                $data = $this->executeSwot($name, $checked['arguments'], $actorUserId);
                return ['ok' => true, 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'SWOT action completed.', 'data' => $data];
            }

            // The native service returns complete scenes so the UI can render
            // them. MCP/agent receipts only need bounded metadata and previews;
            // they must never duplicate embedded image assets in transcripts.
            $data = $this->compactWhiteboardData($name, $this->executeWhiteboard($name, $checked['arguments'], $actorUserId));
            return [
                'ok' => true,
                'text' => $this->whiteboardSummary($name, $data),
                'data' => $data,
            ];
        });
    }

    /**
     * MCP advertises the shared catalog only to a currently valid principal.
     * Legacy API-key rows have no Julianna account, so the native Whiteboard
     * service cannot authorize them. They may still use eligible platform
     * tools; human Bearer-token principals receive the complete catalog.
     * Individual project and tool permissions remain checked on every call.
     *
     * @return list<array<string, mixed>>
     */
    public function advertisedMcpTools(): array
    {
        $actorUserId = (int) session('userdata.id');
        if ($actorUserId < 1) {
            return [];
        }

        try {
            return $this->withActor($actorUserId, 'mcp', function () use ($actorUserId): array {
                $user = $this->db->table('zp_user')->where('id', $actorUserId)->first();
                $account = $this->accounts->findByUserId($actorUserId);
                $apiPrincipal = $account === null && strtolower((string) ($user->source ?? '')) === 'api';
                $definitions = $this->catalog->definitions();

                return $apiPrincipal
                    ? array_values(array_filter($definitions, fn (array $definition): bool => $this->catalog->isPlatformTool($definition['name'])))
                    : $definitions;
            });
        } catch (AuthorizationException) {
            // A stale session or revoked principal must not receive a tool list.
            return [];
        }
    }

    /** @param array<string, mixed> $arguments
     * @return array{definition:array<string,mixed>,arguments:array<string,mixed>,project_id:?int,project_ids:list<int>}
     */
    private function authorizeCurrent(string $name, array $arguments, int $actorUserId, ?int $selectedProjectId, string $source): array
    {
        if (! in_array($source, ['interactive', 'background', 'mcp', 'publish'], true)) {
            throw new InvalidArgumentException('Unknown tool source.');
        }
        if (in_array($name, ['searchWeb', 'researchWeb'], true) && $source === 'background') {
            throw new AuthorizationException('Background web searches are disabled.');
        }
        $definition = $this->catalog->definition($name);
        $this->validator->validate($arguments, $definition['input_schema']);
        if ($this->catalog->isPlatformTool($name)) {
            $arguments = $this->platform->validate($name, $arguments);
        }
        $projectIds = $this->resolveProjectIds($definition['project_scope'], $arguments);
        $effect = $definition['effect'];
        if ($effect !== 'read' && in_array($source, ['interactive', 'background', 'publish'], true)) {
            if ($selectedProjectId === null || $selectedProjectId < 1 || $projectIds !== [$selectedProjectId]) {
                throw new AuthorizationException('Select the project for this action.');
            }
        }
        if ($projectIds === []) {
            $this->permissions->authorize($definition['permission']);
        } else {
            foreach ($projectIds as $projectId) {
                $this->assertProjectAccessible($projectId, $actorUserId);
                $this->permissions->authorize('projects.view', $projectId);
                $this->permissions->authorize($definition['permission'], $projectId);
            }
        }
        if ($effect !== 'read' && $source === 'background') {
            $this->assertBackgroundAgentRunning($selectedProjectId, $actorUserId);
        }

        return [
            'definition' => $definition,
            'arguments' => $arguments,
            'project_id' => count($projectIds) === 1 ? $projectIds[0] : null,
            'project_ids' => $projectIds,
        ];
    }

    /** @param array<string,mixed> $arguments
     * @return list<int>
     */
    private function resolveProjectIds(string $scope, array $arguments): array
    {
        $ids = match ($scope) {
            'accessible', 'personal' => [],
            'projectId' => [(int) $arguments['projectId']],
            'optionalProjectId' => isset($arguments['projectId']) ? [(int) $arguments['projectId']] : [],
            'projectIds' => array_map('intval', $arguments['projectIds']),
            'taskProjects' => array_map(static fn (array $row): int => (int) $row['projectId'], $arguments['tasks']),
            'ticket' => [$this->ticketProject((int) $arguments['id'])],
            'parentTicket' => [$this->ticketProject((int) $arguments['parentTicket'])],
            'timeTicket' => [$this->ticketProject((int) $arguments['ticketId'])],
            'taskTicket' => [$this->ticketProject((int) $arguments['taskId'])],
            'updateTickets' => array_map(fn (array $row): int => $this->ticketProject((int) $row['id']), $arguments['updates']),
            'scheduleTickets' => array_map(fn (array $row): int => $this->ticketProject((int) $row['taskId']), $arguments['schedules']),
            'goal' => [$this->goalProject((int) ($arguments['goalId'] ?? $arguments['id']))],
            'goalboard' => [$this->goalboardProject((int) $arguments['canvasId'])],
            'commentTarget' => [$arguments['module'] === 'project'
                ? (int) $arguments['entityId'] : $this->ticketProject((int) $arguments['entityId'])],
            'whiteboard' => [$this->whiteboardProject((int) $arguments['boardId'])],
            'swotBlueprint' => [$this->swotProject((int) $arguments['boardId'])],
            default => throw new InvalidArgumentException('Unknown project scope.'),
        };
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (in_array(0, $ids, true) || in_array(-1, $ids, true)) {
            throw new AuthorizationException;
        }

        return $ids;
    }

    private function ticketProject(int $ticketId): int
    {
        $ticket = $ticketId > 0 ? $this->tickets->getTicket($ticketId) : false;
        $projectId = is_object($ticket) ? (int) ($ticket->projectId ?? 0) : 0;
        if ($projectId < 1) {
            throw new AuthorizationException;
        }

        return $projectId;
    }

    private function goalProject(int $goalId): int
    {
        $goal = $goalId > 0 ? $this->goals->getGoalItem($goalId) : false;
        $projectId = is_array($goal) ? (int) ($goal['projectId'] ?? 0) : 0;
        if ($projectId < 1) {
            throw new AuthorizationException;
        }

        return $projectId;
    }

    private function goalboardProject(int $boardId): int
    {
        $board = $boardId > 0 ? $this->goals->getSingleCanvas($boardId) : false;
        $projectId = is_array($board) ? (int) ($board['projectId'] ?? 0) : 0;
        if ($projectId < 1) {
            throw new AuthorizationException;
        }

        return $projectId;
    }

    private function whiteboardProject(int $boardId): int
    {
        $projectId = $boardId > 0 ? (int) $this->db->table('julianna_whiteboards')->where('id', $boardId)->value('project_id') : 0;
        if ($projectId < 1) {
            throw new AuthorizationException;
        }

        return $projectId;
    }

    private function swotProject(int $boardId): int
    {
        $projectId = $boardId > 0 ? (int) $this->db->table('zp_canvas')
            ->where('id', $boardId)->where('type', 'swotcanvas')->value('projectId') : 0;
        if ($projectId < 1) {
            throw new AuthorizationException;
        }

        return $projectId;
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function executeSwot(string $name, array $arguments, int $actorUserId): array
    {
        /** @var Blueprints $blueprints */
        $blueprints = app()->make(Blueprints::class);
        if ($name === 'listSwotBlueprints') {
            $boards = $this->db->table('zp_canvas')->where('type', 'swotcanvas')
                ->where('projectId', (int) $arguments['projectId'])
                ->orderBy('id')->limit(50)->get(['id', 'title', 'projectId']);
            return ['boards' => array_map(static fn ($row): array => [
                'id' => (int) $row->id, 'title' => (string) $row->title,
                'projectId' => (int) $row->projectId,
            ], $boards->all())];
        }
        if ($name === 'getSwotBlueprint') {
            $boardId = (int) $arguments['boardId'];
            $board = $blueprints->getBoard($boardId, 'swotcanvas');
            if ($board === false || $board === []) {
                throw new AuthorizationException;
            }
            $rows = $this->db->table('zp_canvas_items')->where('canvasId', $boardId)
                ->orderBy('id')->limit(200)->get(['id', 'box', 'description', 'data', 'assumptions']);
            return [
                'boardId' => $boardId, 'projectId' => (int) $board[0]['projectId'],
                'title' => (string) $board[0]['title'],
                'url' => '/blueprints/swot/showCanvas/'.$boardId,
                'items' => array_map(static fn ($row): array => [
                    'id' => (int) $row->id,
                    'quadrant' => str_replace('swot_', '', (string) $row->box),
                    'description' => (string) $row->description,
                    'data' => (string) $row->data,
                    'assumptions' => (string) $row->assumptions,
                ], $rows->all()),
                'truncated' => $rows->count() === 200,
            ];
        }
        if ($name === 'createSwotBlueprint') {
            $title = trim((string) $arguments['title']);
            if ($title === '') {
                throw new InvalidArgumentException('SWOT title cannot be blank.');
            }
            $projectId = (int) $arguments['projectId'];
            $existing = $this->db->table('zp_canvas')->where('type', 'swotcanvas')
                ->where('projectId', $projectId)->where('title', $title)->value('id');
            if ($existing !== null) {
                return ['boardId' => (int) $existing, 'projectId' => $projectId, 'title' => $title, 'existing' => true, 'url' => '/blueprints/swot/showCanvas/'.$existing];
            }
            $boardId = $blueprints->createBoard(['projectId' => $projectId, 'author' => $actorUserId, 'title' => $title], 'swotcanvas');
            if ($boardId === false) {
                throw new \RuntimeException('SWOT board could not be created.');
            }
            return ['boardId' => (int) $boardId, 'projectId' => $projectId, 'title' => $title, 'url' => '/blueprints/swot/showCanvas/'.$boardId];
        }
        if ($name === 'addSwotItems') {
            $boardId = (int) $arguments['boardId'];
            $created = $this->db->transaction(function () use ($arguments, $boardId, $actorUserId, $blueprints): array {
                $items = [];
                $seen = [];
                foreach ($arguments['items'] as $item) {
                    $description = trim((string) $item['description']);
                    if ($description === '') {
                        throw new InvalidArgumentException('SWOT item description cannot be blank.');
                    }
                    $box = 'swot_'.$item['quadrant'];
                    $key = $box.'|'.mb_strtolower($description);
                    if (isset($seen[$key]) || $this->db->table('zp_canvas_items')
                        ->where('canvasId', $boardId)->where('box', $box)->where('description', $description)->exists()) {
                        continue;
                    }
                    $seen[$key] = true;
                    $id = $blueprints->createCanvasItem([
                        'canvasId' => $boardId,
                        'author' => $actorUserId,
                        'box' => $box,
                        'description' => $description,
                        'relates' => 'relates_none',
                        'data' => trim((string) ($item['data'] ?? '')),
                        'assumptions' => trim((string) ($item['assumptions'] ?? '')),
                    ], 'swotcanvas');
                    if ($id === false) {
                        throw new \RuntimeException('SWOT item could not be created.');
                    }
                    $items[] = ['id' => (int) $id, 'quadrant' => $item['quadrant'], 'description' => $description];
                }
                return $items;
            });
            return ['boardId' => $boardId, 'created' => $created, 'createdCount' => count($created), 'url' => '/blueprints/swot/showCanvas/'.$boardId];
        }

        throw new InvalidArgumentException('Unknown tool.');
    }

    private function assertProjectAccessible(int $projectId, int $actorUserId): void
    {
        $project = $this->db->table('zp_projects')->where('id', $projectId)->first();
        $user = $this->db->table('zp_user')->where('id', $actorUserId)->first();
        if ($project === null || $user === null || (string) ($project->state ?? '') === '-1') {
            throw new AuthorizationException;
        }
        if (in_array((string) ($user->role ?? ''), ['40', '50'], true)
            || (string) ($project->psettings ?? '') === 'all'
            || ((string) ($project->psettings ?? '') === 'clients' && (int) ($project->clientId ?? -1) === (int) ($user->clientId ?? -2))
            || $this->db->table('zp_relationuserproject')->where('userId', $actorUserId)->where('projectId', $projectId)->exists()) {
            return;
        }

        throw new AuthorizationException;
    }

    private function assertBackgroundAgentRunning(?int $projectId, int $actorUserId): void
    {
        if ($projectId === null) {
            throw new AuthorizationException;
        }
        $row = $this->db->table('julianna_agent_projects')->where('project_id', $projectId)->first();
        if ($row === null || ! (bool) $row->enabled || (bool) $row->paused
            || (int) $row->enabled_by_user_id !== $actorUserId) {
            throw new AuthorizationException('The project agent is not active.');
        }
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function executeWhiteboard(string $name, array $arguments, int $actorUserId): array
    {
        return match ($name) {
            'listWhiteboards' => ['boards' => $this->whiteboards->listBoards((int) $arguments['projectId'], $actorUserId)],
            'createWhiteboard' => $this->whiteboards->createBoard((int) $arguments['projectId'], $actorUserId, $arguments['title']),
            'getWhiteboard' => $this->whiteboards->board((int) $arguments['boardId'], $actorUserId),
            'saveWhiteboardScene' => $this->whiteboards->saveScene((int) $arguments['boardId'], $actorUserId, (int) $arguments['expectedRevision'], $arguments['scene']),
            'patchWhiteboardScene' => $this->patchWhiteboardScene($arguments, $actorUserId),
            'listWhiteboardRevisions' => ['revisions' => $this->whiteboards->revisions((int) $arguments['boardId'], $actorUserId)],
            'restoreWhiteboardRevision' => $this->whiteboards->restoreRevision((int) $arguments['boardId'], $actorUserId, (int) $arguments['expectedRevision'], (int) $arguments['sourceRevision']),
            default => throw new InvalidArgumentException('Unknown tool.'),
        };
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function patchWhiteboardScene(array $arguments, int $actorUserId): array
    {
        $upserts = $arguments['upsertElements'] ?? [];
        $removals = $arguments['removeElementIds'] ?? [];
        if ($upserts === [] && $removals === [] && ! isset($arguments['backgroundColor'])) {
            throw new InvalidArgumentException('Provide at least one Whiteboard change.');
        }
        $upsertIds = array_column($upserts, 'id');
        if (count($upsertIds) !== count(array_unique($upsertIds)) || count($removals) !== count(array_unique($removals))
            || array_intersect($upsertIds, $removals) !== []) {
            throw new InvalidArgumentException('Whiteboard element IDs must be unique and cannot be both updated and removed.');
        }

        $boardId = (int) $arguments['boardId'];
        $expectedRevision = (int) $arguments['expectedRevision'];
        $board = $this->whiteboards->board($boardId, $actorUserId);
        if ((int) $board['revision'] !== $expectedRevision) {
            throw new WhiteboardConflictException;
        }
        $scene = $board['scene'];
        $indexes = [];
        foreach ($scene['elements'] as $index => $element) {
            $indexes[$element['id']] = $index;
        }
        foreach ($upserts as $elementPatch) {
            $id = $elementPatch['id'];
            if (isset($indexes[$id])) {
                $index = $indexes[$id];
                if (isset($elementPatch['type']) && $elementPatch['type'] !== $scene['elements'][$index]['type']) {
                    throw new InvalidArgumentException('An existing Whiteboard element type cannot be changed.');
                }
                $scene['elements'][$index] = array_replace($scene['elements'][$index], $elementPatch);
            } else {
                if (! isset($elementPatch['type'])) {
                    throw new InvalidArgumentException('A new Whiteboard element needs a type.');
                }
                $scene['elements'][] = $this->newWhiteboardElement($elementPatch);
            }
        }
        if ($removals !== []) {
            $remove = array_fill_keys($removals, true);
            $scene['elements'] = array_values(array_filter(
                $scene['elements'],
                static fn (array $element): bool => ! isset($remove[$element['id']]),
            ));
        }
        if (isset($arguments['backgroundColor'])) {
            $scene['appState']['viewBackgroundColor'] = $arguments['backgroundColor'];
        }

        // saveScene locks the row, checks the expected revision again, validates
        // the complete resulting scene, and appends an immutable revision.
        return $this->whiteboards->saveScene($boardId, $actorUserId, $expectedRevision, $scene);
    }

    /**
     * The model-facing patch tool offers a deliberately small, renderable
     * Excalidraw shape vocabulary. Full scene replacement remains available
     * for native Excalidraw clients, but a model need not invent version and
     * styling fields merely to sketch a rectangle or a note.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    private function newWhiteboardElement(array $patch): array
    {
        $type = $patch['type'];
        if (! in_array($type, ['rectangle', 'ellipse', 'diamond', 'text'], true)) {
            throw new InvalidArgumentException('New patch elements support rectangle, ellipse, diamond, and text only; use a complete native scene for other types.');
        }

        $millis = (int) floor(microtime(true) * 1000);
        $base = [
            'id' => $patch['id'],
            'type' => $type,
            'x' => 0,
            'y' => 0,
            'width' => $type === 'text' ? 240 : 180,
            'height' => $type === 'text' ? 50 : 120,
            'angle' => 0,
            'strokeColor' => '#1e1e1e',
            'backgroundColor' => 'transparent',
            'fillStyle' => 'solid',
            'strokeWidth' => 2,
            'strokeStyle' => 'solid',
            'roundness' => $type === 'rectangle' ? ['type' => 3] : null,
            'roughness' => 1,
            'opacity' => 100,
            'seed' => random_int(1, 2147483647),
            'version' => 1,
            'versionNonce' => random_int(1, 2147483647),
            'index' => null,
            'isDeleted' => false,
            'groupIds' => [],
            'frameId' => null,
            'boundElements' => null,
            'updated' => $millis,
            'link' => null,
            'locked' => false,
        ];
        if ($type === 'text') {
            $text = is_string($patch['text'] ?? null) ? $patch['text'] : '';
            $base += [
                'fontSize' => 20,
                'fontFamily' => 1,
                'text' => $text,
                'originalText' => $text,
                'textAlign' => 'left',
                'verticalAlign' => 'top',
                'containerId' => null,
                'autoResize' => true,
                'lineHeight' => 1.25,
            ];
        }

        return array_replace($base, $patch);
    }

    /**
     * Never persist image data or an unbounded scene in an agent receipt. The
     * full-fidelity board remains available through the Whiteboards service.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function compactWhiteboardData(string $name, array $data): array
    {
        if ($name === 'listWhiteboards') {
            $allBoards = $data['boards'] ?? [];
            $boards = array_slice($allBoards, 0, 50);
            $summaries = array_map(static fn (array $board): array => [
                'id' => (int) ($board['id'] ?? 0),
                'title' => mb_substr((string) ($board['title'] ?? ''), 0, 160),
                'revision' => (int) ($board['revision'] ?? 0),
            ], $boards);

            return ['boards' => $summaries, 'count' => count($allBoards), 'truncated' => count($allBoards) > count($summaries)];
        }
        if ($name === 'listWhiteboardRevisions') {
            $allRevisions = $data['revisions'] ?? [];

            return [
                'revisions' => array_map(static fn (array $revision): array => [
                    'revision' => (int) ($revision['revision'] ?? 0),
                    'author_user_id' => (int) ($revision['author_user_id'] ?? 0),
                    'created_at' => (string) ($revision['created_at'] ?? ''),
                ], array_slice($allRevisions, 0, 100)),
                'count' => count($allRevisions),
                'truncated' => count($allRevisions) > 100,
            ];
        }

        $summary = [
            'boardId' => (int) ($data['boardId'] ?? $data['id'] ?? 0),
            'projectId' => (int) ($data['project_id'] ?? 0),
            'title' => mb_substr((string) ($data['title'] ?? ''), 0, 160),
            'revision' => (int) ($data['revision'] ?? 0),
        ];
        if ($name === 'getWhiteboard') {
            $scene = $data['scene'] ?? [];
            $elements = is_array($scene['elements'] ?? null) ? $scene['elements'] : [];
            $files = is_array($scene['files'] ?? null) ? $scene['files'] : [];
            $summary['backgroundColor'] = (string) ($scene['appState']['viewBackgroundColor'] ?? '#ffffff');
            $summary['elementCount'] = count($elements);
            $summary['fileCount'] = count($files);
            $summary['filesPreview'] = [];
            foreach (array_slice($files, 0, 50, true) as $fileId => $file) {
                if (is_array($file)) {
                    $summary['filesPreview'][] = ['id' => (string) $fileId, 'mimeType' => (string) ($file['mimeType'] ?? '')];
                }
            }
            $preview = [];
            foreach (array_slice($elements, 0, 50) as $element) {
                if (! is_array($element)) {
                    continue;
                }
                $preview[] = [
                    'id' => (string) ($element['id'] ?? ''),
                    'type' => (string) ($element['type'] ?? ''),
                    'text' => mb_substr((string) ($element['text'] ?? ''), 0, 300),
                    'x' => $element['x'] ?? null,
                    'y' => $element['y'] ?? null,
                    'width' => $element['width'] ?? null,
                    'height' => $element['height'] ?? null,
                ];
            }
            $summary['elementsPreview'] = $preview;
            $summary['previewTruncated'] = count($elements) > count($preview);
        }

        return $summary;
    }

    /** Never send image data or an unbounded scene back into model context. */
    private function whiteboardSummary(string $name, array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? 'Whiteboard action completed.' : $encoded;
    }

    /** @template T
     * @param callable():T $callback
     * @return T
     */
    private function withActor(int $actorUserId, string $source, callable $callback): mixed
    {
        if ($actorUserId < 1) {
            throw new AuthorizationException;
        }
        if ($source !== 'background' && (int) session('userdata.id') !== $actorUserId) {
            throw new AuthorizationException;
        }
        $account = $this->accounts->findByUserId($actorUserId);
        $user = $this->db->table('zp_user')->where('id', $actorUserId)->first();
        // Legacy API keys are independent, revocable credentials backed by
        // their own zp_user row rather than a Julianna human account. Only the
        // authenticated MCP transport may use that separate principal type.
        $apiPrincipal = $source === 'mcp' && $account === null && $user !== null
            && strtolower((string) ($user->source ?? '')) === 'api';
        if ($user === null || strtolower((string) ($user->status ?? '')) !== 'a'
            || (! $apiPrincipal && ($account === null || ! $account->canAuthenticate() || $account->mfaConfirmedAt === null))) {
            throw new AuthorizationException;
        }

        $store = session();
        $hadUserdata = $store->exists('userdata');
        $previousUserdata = $store->get('userdata');
        $hadSettings = $store->exists('usersettings');
        $previousSettings = $store->get('usersettings');
        $hadProject = $store->exists('currentProject');
        $previousProject = $store->get('currentProject');
        try {
            $userdata = UserSessionBuilder::build((array) $user, twoFAVerified: true);
            $store->put('userdata', $userdata);
            $store->put('usersettings', $userdata['settings']);
            $store->forget('currentProject');

            return $callback();
        } finally {
            $hadUserdata ? $store->put('userdata', $previousUserdata) : $store->forget('userdata');
            $hadSettings ? $store->put('usersettings', $previousSettings) : $store->forget('usersettings');
            $hadProject ? $store->put('currentProject', $previousProject) : $store->forget('currentProject');
        }
    }
}
