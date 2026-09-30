<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Services;

use InvalidArgumentException;

/**
 * The single public capability list for the in-app agent and the MCP transport.
 *
 * The existing platform adapter performs its detailed entity checks. Nothing from
 * the retired Idea Room MCP provider is registered here. An explicit allow-list
 * keeps unsafe, irreversible and publication tools out of autonomous execution.
 */
final class ToolCatalog
{
    /** @var array<string, array{permission:string,project_scope:string,recovery:string,effect?:string}> */
    private const PLATFORM = [
        'getAllProjects' => ['permission' => 'projects.view', 'project_scope' => 'accessible', 'recovery' => 'none'],
        'findProject' => ['permission' => 'projects.view', 'project_scope' => 'accessible', 'recovery' => 'none'],
        'getProject' => ['permission' => 'projects.view', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'getAllGoals' => ['permission' => 'goals.view', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'getGoal' => ['permission' => 'goals.view', 'project_scope' => 'goal', 'recovery' => 'none'],
        'createGoalboard' => ['permission' => 'goals.create', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'createGoal' => ['permission' => 'goals.create', 'project_scope' => 'goalboard', 'recovery' => 'none'],
        'editGoal' => ['permission' => 'goals.edit', 'project_scope' => 'goal', 'recovery' => 'none'],
        'findTasks' => ['permission' => 'tickets.view', 'project_scope' => 'projectIds', 'recovery' => 'none'],
        'getTicket' => ['permission' => 'tickets.view', 'project_scope' => 'ticket', 'recovery' => 'none'],
        'getMilestone' => ['permission' => 'tickets.view', 'project_scope' => 'ticket', 'recovery' => 'none'],
        'addTask' => ['permission' => 'tickets.create', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'bulkAddTasks' => ['permission' => 'tickets.create', 'project_scope' => 'taskProjects', 'recovery' => 'none'],
        'bulkEditTasks' => ['permission' => 'tickets.edit', 'project_scope' => 'updateTickets', 'recovery' => 'none'],
        'bulkScheduleTasks' => ['permission' => 'tickets.edit', 'project_scope' => 'scheduleTickets', 'recovery' => 'none'],
        'addMilestone' => ['permission' => 'tickets.create', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'addSubtask' => ['permission' => 'tickets.create', 'project_scope' => 'parentTicket', 'recovery' => 'none'],
        'editTask' => ['permission' => 'tickets.edit', 'project_scope' => 'ticket', 'recovery' => 'none'],
        'editMilestone' => ['permission' => 'tickets.edit', 'project_scope' => 'ticket', 'recovery' => 'none'],
        'getCalendar' => ['permission' => 'calendar.view', 'project_scope' => 'personal', 'recovery' => 'none'],
        'scheduleTaskOnCalendar' => ['permission' => 'tickets.edit', 'project_scope' => 'ticket', 'recovery' => 'none'],
        'getUserTimesheets' => ['permission' => 'timesheets.view', 'project_scope' => 'optionalProjectId', 'recovery' => 'none'],
        'logTime' => ['permission' => 'timesheets.create', 'project_scope' => 'timeTicket', 'recovery' => 'none'],
        'startTimer' => ['permission' => 'timesheets.create', 'project_scope' => 'taskTicket', 'recovery' => 'none'],
        'getAllProjectComments' => ['permission' => 'comments.view', 'project_scope' => 'projectId', 'recovery' => 'none'],
        'addComment' => ['permission' => 'comments.create', 'project_scope' => 'commentTarget', 'recovery' => 'none', 'effect' => 'communication'],
        'addProjectStatusUpdate' => ['permission' => 'comments.create', 'project_scope' => 'projectId', 'recovery' => 'none', 'effect' => 'communication'],
    ];

    public function __construct(private readonly PlatformToolRegistry $platform) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->platform->definitions() as $definition) {
            $metadata = self::PLATFORM[$definition['name']] ?? null;
            if ($metadata === null) {
                continue;
            }
            $definitions[] = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'input_schema' => $definition['input_schema'],
                'project_scope' => $metadata['project_scope'],
                'permission' => $metadata['permission'],
                'effect' => $metadata['effect'] ?? ($definition['write'] ? 'write' : 'read'),
                'recovery' => $metadata['recovery'],
            ];
        }

        return [...$definitions, ...$this->whiteboardDefinitions(), ...$this->swotDefinitions(), ...$this->webDefinitions()];
    }

    /** @return array<string, mixed> */
    public function definition(string $name): array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        throw new InvalidArgumentException('Unknown tool.');
    }

    public function isPlatformTool(string $name): bool
    {
        return isset(self::PLATFORM[$name]);
    }

    /** @return list<array<string, mixed>> */
    private function webDefinitions(): array
    {
        if (! WebSearch::configured()) {
            return [];
        }

        $input = [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]],
            'required' => ['query'],
            'additionalProperties' => false,
        ];

        return [[
            'name' => 'searchWeb',
            'description' => 'Search the live public web. Returns up to five bounded search-result snippets and source URLs, not full pages. Read-only; do not include private workspace information or credentials in the query.',
            'input_schema' => $input,
            'project_scope' => 'accessible',
            'permission' => 'projects.view',
            'effect' => 'read',
            'recovery' => 'none',
        ], [
            'name' => 'researchWeb',
            'description' => 'Research a public-web question using up to three source-linked, bounded extracts from live pages. Read-only and not a full browser. Never include private workspace information or credentials in the query.',
            'input_schema' => $input,
            'project_scope' => 'accessible',
            'permission' => 'projects.view',
            'effect' => 'read',
            'recovery' => 'none',
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function swotDefinitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $schema = static fn (array $properties, array $required): array => [
            'type' => 'object', 'properties' => $properties, 'required' => $required,
            'additionalProperties' => false,
        ];
        $entry = static fn (string $name, string $description, array $input, string $scope, string $permission, string $effect): array => [
            'name' => $name, 'description' => $description, 'input_schema' => $input,
            'project_scope' => $scope, 'permission' => $permission,
            'effect' => $effect, 'recovery' => 'none',
        ];

        return [
            $entry('listSwotBlueprints', 'List the native Think → Blueprints SWOT boards in a project.', $schema(['projectId' => $id], ['projectId']), 'projectId', 'blueprints.view', 'read'),
            $entry('getSwotBlueprint', 'Read a native SWOT board and its strengths, weaknesses, opportunities and threats.', $schema(['boardId' => $id], ['boardId']), 'swotBlueprint', 'blueprints.view', 'read'),
            $entry('createSwotBlueprint', 'Create a native SWOT board in Think → Blueprints. Check existing boards first to avoid duplicates.', $schema([
                'projectId' => $id,
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
            ], ['projectId', 'title']), 'projectId', 'blueprints.create', 'write'),
            $entry('addSwotItems', 'Add editable entries to a native SWOT board. Read the board first; this appends without replacing existing entries. Put a concise point in description and optional context in data or assumptions. Clearly label unverified suggestions in assumptions.', $schema([
                'boardId' => $id,
                'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $schema([
                    'quadrant' => ['type' => 'string', 'enum' => ['strengths', 'weaknesses', 'opportunities', 'threats']],
                    'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    'data' => ['type' => 'string', 'maxLength' => 2000],
                    'assumptions' => ['type' => 'string', 'maxLength' => 2000],
                ], ['quadrant', 'description'])],
            ], ['boardId', 'items']), 'swotBlueprint', 'blueprints.create', 'write'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function whiteboardDefinitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $elementId = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[a-zA-Z0-9_-]+$'];
        $title = ['type' => 'string', 'minLength' => 1, 'maxLength' => 160];
        $scene = [
            'type' => 'object',
            'properties' => [
                'elements' => ['type' => 'array', 'maxItems' => 5000, 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'appState' => ['type' => 'object', 'additionalProperties' => true],
                'files' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['elements', 'appState', 'files'],
            'additionalProperties' => false,
        ];
        $schema = static fn (array $properties, array $required = []): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $entry = static fn (string $name, string $description, array $input, string $scope, string $permission, string $effect, string $recovery): array => [
            'name' => $name,
            'description' => $description,
            'input_schema' => $input,
            'project_scope' => $scope,
            'permission' => $permission,
            'effect' => $effect,
            'recovery' => $recovery,
        ];

        return [
            $entry('listWhiteboards', 'List saved Whiteboards in a project.', $schema(['projectId' => $id], ['projectId']), 'projectId', 'whiteboards.view', 'read', 'none'),
            $entry('createWhiteboard', 'Create a saved, project-shared Whiteboard.', $schema(['projectId' => $id, 'title' => $title], ['projectId', 'title']), 'projectId', 'whiteboards.create', 'write', 'none'),
            $entry('getWhiteboard', 'Read a bounded Whiteboard element preview and current revision; use revision-checked patches to edit.', $schema(['boardId' => $id], ['boardId']), 'whiteboard', 'whiteboards.view', 'read', 'none'),
            $entry('saveWhiteboardScene', 'Replace a Whiteboard scene only if its revision matches.', $schema(['boardId' => $id, 'expectedRevision' => ['type' => 'integer', 'minimum' => 0], 'scene' => $scene], ['boardId', 'expectedRevision', 'scene']), 'whiteboard', 'whiteboards.edit', 'write', 'revision'),
            $entry('patchWhiteboardScene', 'Add, update or remove up to 50 Whiteboard elements without replacing the rest of the scene; requires the current revision. New rectangle, ellipse, diamond, or text elements receive native defaults. Existing elements may receive only changed fields.', $schema([
                'boardId' => $id,
                'expectedRevision' => ['type' => 'integer', 'minimum' => 0],
                'upsertElements' => ['type' => 'array', 'maxItems' => 50, 'description' => 'Existing IDs may receive partial field updates. New IDs need id and type (rectangle, ellipse, diamond, or text), plus optional geometry, style, and text.', 'items' => [
                    'type' => 'object',
                    'properties' => ['id' => $elementId, 'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
                    'required' => ['id'],
                    'additionalProperties' => true,
                ]],
                'removeElementIds' => ['type' => 'array', 'maxItems' => 50, 'items' => $elementId],
                'backgroundColor' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'],
            ], ['boardId', 'expectedRevision']), 'whiteboard', 'whiteboards.edit', 'write', 'revision'),
            $entry('listWhiteboardRevisions', 'List durable revisions for a Whiteboard.', $schema(['boardId' => $id], ['boardId']), 'whiteboard', 'whiteboards.view', 'read', 'none'),
            $entry('restoreWhiteboardRevision', 'Restore a prior Whiteboard revision when the current revision matches.', $schema(['boardId' => $id, 'expectedRevision' => ['type' => 'integer', 'minimum' => 0], 'sourceRevision' => ['type' => 'integer', 'minimum' => 0]], ['boardId', 'expectedRevision', 'sourceRevision']), 'whiteboard', 'whiteboards.edit', 'write', 'revision'),
        ];
    }
}
