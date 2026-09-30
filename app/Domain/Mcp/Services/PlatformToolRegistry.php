<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Calendar\Permissions\CalendarPermissions;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Calendar\Tools\AddCalendarEventTool;
use Leantime\Domain\Calendar\Tools\DeleteCalendarEventTool;
use Leantime\Domain\Calendar\Tools\EditCalendarEventTool;
use Leantime\Domain\Calendar\Tools\GetCalendarTool;
use Leantime\Domain\Calendar\Tools\ScheduleTaskOnCalendarTool;
use Leantime\Domain\Comments\Permissions\CommentsPermissions;
use Leantime\Domain\Comments\Tools\AddCommentTool;
use Leantime\Domain\Comments\Tools\AddProjectStatusUpdateTool;
use Leantime\Domain\Comments\Tools\GetAllProjectCommentsTool;
use Leantime\Domain\Goalcanvas\Permissions\GoalcanvasPermissions;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\Goalcanvas\Tools\CreateGoalboardTool;
use Leantime\Domain\Goalcanvas\Tools\CreateGoalTool;
use Leantime\Domain\Goalcanvas\Tools\EditGoalTool;
use Leantime\Domain\Goalcanvas\Tools\GetAllGoalsTool;
use Leantime\Domain\Goalcanvas\Tools\GetGoalTool;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Projects\Tools\AddProjectTool;
use Leantime\Domain\Projects\Tools\FindProjectTool;
use Leantime\Domain\Projects\Tools\GetAllProjectsTool;
use Leantime\Domain\Projects\Tools\GetProjectTool;
use Leantime\Domain\Tickets\Permissions\TicketsPermissions;
use Leantime\Domain\Tickets\Services\Tickets;
use Leantime\Domain\Tickets\Tools\AddMilestoneTool;
use Leantime\Domain\Tickets\Tools\AddSubtaskTool;
use Leantime\Domain\Tickets\Tools\AddTaskTool;
use Leantime\Domain\Tickets\Tools\BulkAddTasksTool;
use Leantime\Domain\Tickets\Tools\BulkEditTasksTool;
use Leantime\Domain\Tickets\Tools\BulkScheduleTasksTool;
use Leantime\Domain\Tickets\Tools\EditMilestoneTool;
use Leantime\Domain\Tickets\Tools\EditTaskTool;
use Leantime\Domain\Tickets\Tools\FindTasksTool;
use Leantime\Domain\Tickets\Tools\GetMilestoneTool;
use Leantime\Domain\Tickets\Tools\GetTaskTool;
use Leantime\Domain\Timesheets\Permissions\TimesheetsPermissions;
use Leantime\Domain\Timesheets\Services\Timesheets;
use Leantime\Domain\Timesheets\Tools\GetUserTimesheetsTool;
use Leantime\Domain\Timesheets\Tools\LogTimeTool;
use Leantime\Domain\Timesheets\Tools\StartTimerTool;
use Leantime\Domain\Timesheets\Tools\StopTimerTool;
use RuntimeException;

/**
 * Deliberately small, explicit bridge to existing domain tools. Nothing supplied by a model
 * is interpreted as a PHP class/method name. Every execution re-checks the live session,
 * entity ownership, project membership and capability, including after confirmation.
 */
class PlatformToolRegistry
{
    private const MAX_RESULT_LENGTH = 4000;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly Projects $projects,
        private readonly Tickets $tickets,
        private readonly Goalcanvas $goals,
        private readonly Calendar $calendar,
    ) {}

    /** @return list<array{name:string,description:string,input_schema:array,write:bool,destructive:bool}> */
    public function definitions(): array
    {
        return array_values(array_map(static fn (array $spec): array => [
            'name' => $spec['name'],
            'description' => $spec['description'],
            'input_schema' => $spec['input_schema'],
            'write' => $spec['write'],
            'destructive' => $spec['destructive'],
        ], $this->specifications()));
    }

    /** @return array{write:bool,destructive:bool} */
    public function classify(string $name): array
    {
        $spec = $this->specification($name);

        return ['write' => $spec['write'], 'destructive' => $spec['destructive']];
    }

    /**
     * Check all arguments before either displaying a confirmation or executing a read.
     * Unknown/extra fields and unbounded nested objects are rejected, never forwarded.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validate(string $name, array $arguments): array
    {
        $schema = $this->specification($name)['input_schema'];
        $this->validateValue($arguments, $schema, 'arguments');

        return $arguments;
    }

    /**
     * Autonomous writes belong to the enabled project, even if the user could
     * manually access another project. Unscoped tools fail closed here; normal
     * per-entity permissions are checked again by execute().
     *
     * @param array<string, mixed> $arguments
     */
    public function assertProjectScope(string $name, array $arguments, int $projectId): void
    {
        $arguments = $this->validate($name, $arguments);
        if ($projectId < 1 || ! $this->classify($name)['write']) {
            throw new AuthorizationException;
        }

        $targets = match ($name) {
            'createGoalboard', 'addTask', 'addMilestone', 'addProjectStatusUpdate' => [(int) $arguments['projectId']],
            'bulkAddTasks' => array_map(static fn (array $task): int => (int) $task['projectId'], $arguments['tasks']),
            'bulkEditTasks' => array_map(fn (array $item): int => (int) $this->ticket((int) $item['id'])->projectId, $arguments['updates']),
            'bulkScheduleTasks' => array_map(fn (array $item): int => (int) $this->ticket((int) $item['taskId'])->projectId, $arguments['schedules']),
            'createGoal' => [(int) $this->board((int) $arguments['canvasId'])['projectId']],
            'editGoal' => [(int) $this->goal((int) $arguments['id'])['projectId']],
            'editTask', 'editMilestone', 'scheduleTaskOnCalendar' => [(int) $this->ticket((int) $arguments['id'])->projectId],
            'addSubtask' => [(int) $this->ticket((int) $arguments['parentTicket'])->projectId],
            'logTime' => [(int) $this->ticket((int) $arguments['ticketId'])->projectId],
            'startTimer' => [(int) $this->ticket((int) $arguments['taskId'])->projectId],
            'stopTimer' => isset($arguments['ticketId']) ? [(int) $this->ticket((int) $arguments['ticketId'])->projectId] : [],
            'addComment' => [$arguments['module'] === 'project'
                ? (int) $arguments['entityId']
                : (int) $this->ticket((int) $arguments['entityId'])->projectId],
            default => [],
        };
        if ($targets === [] || in_array(false, array_map(static fn (int $target): bool => $target === $projectId, $targets), true)) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $arguments */
    public function authorizeForProject(string $name, array $arguments, int $projectId): void
    {
        $this->assertProjectScope($name, $arguments, $projectId);
        $this->authorizeArguments($name, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok:bool,text:string}
     */
    public function execute(string $name, array $arguments, bool $confirmed = false): array
    {
        $spec = $this->specification($name);
        $arguments = $this->validate($name, $arguments);
        if ($spec['write'] && ! $confirmed) {
            throw new AuthorizationException('Confirmation is required.');
        }
        if ((int) session('userdata.id') < 1) {
            throw new AuthorizationException;
        }

        $arguments = $this->authorizeArguments($name, $arguments);
        if (in_array($name, ['bulkAddTasks', 'bulkEditTasks', 'bulkScheduleTasks'], true)) {
            return $this->executeBulk($name, $arguments);
        }
        if (in_array($name, ['addTask', 'addSubtask'], true)) {
            $params = $arguments;
            $params['type'] = $name === 'addSubtask' ? 'subtask' : 'task';
            if ($name === 'addSubtask') {
                $params['dependingTicketId'] = $params['parentTicket'];
                unset($params['parentTicket']);
            }
            // Let the ticket service select the project's actual NEW status.
            $id = $this->tickets->quickAddTicket($params);

            return [
                'ok' => is_int($id) && $id > 0,
                'text' => is_int($id) && $id > 0 ? "Task created. ID: {$id}" : 'Task could not be created.',
            ];
        }
        if ($name === 'logTime') {
            $result = app()->make(Timesheets::class)->logTime((int) $arguments['ticketId'], [
                'date' => $arguments['date'],
                'hours' => $arguments['hours'],
                'kind' => $arguments['kind'],
                'description' => $arguments['description'] ?? '',
            ]);

            return ['ok' => (bool) $result, 'text' => $result ? 'Time entry logged.' : 'Time entry could not be logged.'];
        }
        /** @var Tool $tool */
        $tool = app()->make($spec['class']);
        $result = $tool->handle($arguments);
        if (! $result instanceof ToolResult) {
            throw new InvalidArgumentException('Tool returned an invalid result.');
        }
        $parts = [];
        foreach ($result->content as $content) {
            $part = $content->toArray();
            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }
        $text = trim(implode("\n", $parts));

        return [
            'ok' => ! $result->isError,
            'text' => mb_substr($text !== '' ? $text : ($result->isError ? 'Action failed.' : 'Action completed.'), 0, self::MAX_RESULT_LENGTH),
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function authorizeArguments(string $name, array $arguments): array
    {
        switch ($name) {
            case 'getAllProjects':
            case 'findProject':
                // Both existing services scope their query to the signed-in user.
                return $arguments;
            case 'addProject':
                $this->permissions->authorize(ProjectsPermissions::CREATE, forceGlobal: true);

                return $arguments;
            case 'getProject':
            case 'getAllGoals':
            case 'getAllProjectComments':
                $projectId = (int) $arguments['projectId'];
                $this->authorizeProject($projectId, match ($name) {
                    'getAllGoals' => GoalcanvasPermissions::VIEW,
                    'getAllProjectComments' => CommentsPermissions::VIEW,
                    default => ProjectsPermissions::VIEW,
                });

                return $arguments;
            case 'createGoalboard':
                $this->authorizeProject((int) $arguments['projectId'], GoalcanvasPermissions::CREATE);

                return $arguments;
            case 'createGoal':
                $board = $this->board((int) $arguments['canvasId']);
                $this->authorizeProject((int) $board['projectId'], GoalcanvasPermissions::CREATE);

                return $arguments;
            case 'getGoal':
            case 'editGoal':
                $goal = $this->goal((int) ($arguments['goalId'] ?? $arguments['id']));
                $this->authorizeProject((int) $goal['projectId'], $name === 'getGoal' ? GoalcanvasPermissions::VIEW : GoalcanvasPermissions::EDIT);

                return $arguments;
            case 'findTasks':
                foreach ($arguments['projectIds'] as $projectId) {
                    $this->authorizeProject((int) $projectId, TicketsPermissions::VIEW);
                }

                return $arguments;
            case 'getTicket':
            case 'getMilestone':
            case 'editTask':
            case 'editMilestone':
            case 'scheduleTaskOnCalendar':
                $ticket = $this->ticket((int) $arguments['id']);
                if (in_array($name, ['getMilestone', 'editMilestone'], true) && $ticket->type !== 'milestone') {
                    throw new AuthorizationException;
                }
                $this->authorizeProject((int) $ticket->projectId, in_array($name, ['editTask', 'editMilestone', 'scheduleTaskOnCalendar'], true) ? TicketsPermissions::EDIT : TicketsPermissions::VIEW);

                return $arguments;
            case 'addTask':
                $this->authorizeProject((int) $arguments['projectId'], TicketsPermissions::CREATE);

                return $arguments;
            case 'bulkAddTasks':
                foreach ($arguments['tasks'] as $task) {
                    $this->authorizeProject((int) $task['projectId'], TicketsPermissions::CREATE);
                }

                return $arguments;
            case 'bulkEditTasks':
            case 'bulkScheduleTasks':
                foreach ($arguments[$name === 'bulkEditTasks' ? 'updates' : 'schedules'] as $item) {
                    $ticket = $this->ticket((int) ($item['id'] ?? $item['taskId']));
                    $this->authorizeProject((int) $ticket->projectId, TicketsPermissions::EDIT);
                }

                return $arguments;
            case 'addMilestone':
                $this->authorizeProject((int) $arguments['projectId'], TicketsPermissions::CREATE);
                $arguments['editorId'] = (int) session('userdata.id');

                return $arguments;
            case 'addSubtask':
                $parent = $this->ticket((int) $arguments['parentTicket']);
                if (! in_array($parent->type, ['task', 'subtask'], true)) {
                    throw new AuthorizationException;
                }
                $this->authorizeProject((int) $parent->projectId, TicketsPermissions::CREATE);
                // Never trust a model-provided child project; pin it to the real parent.
                $arguments['projectId'] = (int) $parent->projectId;

                return $arguments;
            case 'getCalendar':
            case 'addEvent':
            case 'editEvent':
            case 'deleteEvent':
                $permission = match ($name) {
                    'getCalendar' => CalendarPermissions::VIEW,
                    'addEvent' => CalendarPermissions::CREATE,
                    'editEvent' => CalendarPermissions::EDIT,
                    default => CalendarPermissions::DELETE,
                };
                $this->permissions->authorize($permission);
                if (in_array($name, ['editEvent', 'deleteEvent'], true) && ! $this->calendar->getEvent((int) $arguments['id'])) {
                    throw new AuthorizationException;
                }

                return $arguments;
            case 'getUserTimesheets':
                $this->permissions->authorize(TimesheetsPermissions::VIEW, forceGlobal: true);
                if (isset($arguments['projectId'])) {
                    $this->authorizeProject((int) $arguments['projectId'], ProjectsPermissions::VIEW);
                }

                return $arguments;
            case 'logTime':
                $this->permissions->authorize(TimesheetsPermissions::CREATE, forceGlobal: true);
                $ticket = $this->ticket((int) $arguments['ticketId']);
                $this->authorizeProject((int) $ticket->projectId, TicketsPermissions::VIEW);

                return $arguments;
            case 'startTimer':
            case 'stopTimer':
                $this->permissions->authorize(TimesheetsPermissions::CREATE, forceGlobal: true);
                $ticketId = (int) ($arguments['taskId'] ?? $arguments['ticketId'] ?? 0);
                if ($ticketId > 0) {
                    $ticket = $this->ticket($ticketId);
                    $this->authorizeProject((int) $ticket->projectId, TicketsPermissions::VIEW);
                }

                return $arguments;
            case 'addComment':
                $projectId = $arguments['module'] === 'project'
                    ? (int) $arguments['entityId']
                    : (int) $this->ticket((int) $arguments['entityId'])->projectId;
                $this->authorizeProject($projectId, CommentsPermissions::CREATE);

                return $arguments;
            case 'addProjectStatusUpdate':
                $this->authorizeProject((int) $arguments['projectId'], CommentsPermissions::CREATE);

                return $arguments;
        }

        throw new InvalidArgumentException('Unknown tool.');
    }

    /** @param array<string, mixed> $arguments
     * @return array{ok:bool,text:string}
     */
    private function executeBulk(string $name, array $arguments): array
    {
        return DB::transaction(function () use ($name, $arguments): array {
            $ids = [];
            if ($name === 'bulkAddTasks') {
                foreach ($arguments['tasks'] as $task) {
                    $id = $this->tickets->quickAddTicket([
                        'headline' => $task['headline'],
                        'description' => $task['description'] ?? '',
                        'projectId' => $task['projectId'],
                        'type' => 'task',
                    ]);
                    if (! is_int($id) || $id < 1) {
                        throw new RuntimeException('Bulk task creation failed.');
                    }
                    $ids[] = $id;
                }

                return ['ok' => true, 'text' => 'Created task IDs: '.implode(', ', $ids)];
            }
            foreach ($arguments[$name === 'bulkEditTasks' ? 'updates' : 'schedules'] as $item) {
                $id = (int) ($item['id'] ?? $item['taskId']);
                if ($name === 'bulkScheduleTasks') {
                    $start = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $item['editFrom']);
                    $end = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $item['editTo']);
                    if ($start === false || $end === false || $end->getTimestamp() - $start->getTimestamp() < 900) {
                        throw new InvalidArgumentException('Each schedule needs valid ISO 8601 dates and at least 15 minutes.');
                    }
                    $changes = ['editFrom' => $item['editFrom'], 'editTo' => $item['editTo']];
                } else {
                    $changes = $item['params'];
                }
                if (! $this->tickets->patch($id, $changes)) {
                    throw new RuntimeException('Bulk task update failed.');
                }
                $ids[] = $id;
            }

            return ['ok' => true, 'text' => ($name === 'bulkEditTasks' ? 'Updated' : 'Scheduled').' task IDs: '.implode(', ', $ids)];
        });
    }

    private function authorizeProject(int $projectId, string $permission): void
    {
        if ($projectId < 1) {
            throw new AuthorizationException;
        }
        $this->permissions->authorize(ProjectsPermissions::VIEW, $projectId);
        if (! $this->projects->getProject($projectId)) {
            throw new AuthorizationException;
        }
        $this->permissions->authorize($permission, $projectId);
    }

    private function ticket(int $id): object
    {
        $ticket = $id > 0 ? $this->tickets->getTicket($id) : false;
        if (! is_object($ticket) || (int) ($ticket->projectId ?? 0) < 1) {
            throw new AuthorizationException;
        }

        return $ticket;
    }

    /** @return array<string, mixed> */
    private function goal(int $id): array
    {
        $goal = $id > 0 ? $this->goals->getGoalItem($id) : false;
        if (! is_array($goal) || (int) ($goal['projectId'] ?? 0) < 1) {
            throw new AuthorizationException;
        }

        return $goal;
    }

    /** @return array<string, mixed> */
    private function board(int $id): array
    {
        $board = $id > 0 ? $this->goals->getSingleCanvas($id) : false;
        if (! is_array($board) || (int) ($board['projectId'] ?? 0) < 1) {
            throw new AuthorizationException;
        }

        return $board;
    }

    /** @return array<string, mixed> */
    private function specification(string $name): array
    {
        return $this->specifications()[$name] ?? throw new InvalidArgumentException('Unknown tool.');
    }

    /** @return array<string, array<string, mixed>> */
    private function specifications(): array
    {
        $s = static fn (int $max = 255): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $max];
        $i = static fn (): array => ['type' => 'integer', 'minimum' => 1];
        $date = static fn (): array => ['type' => 'string', 'minLength' => 8, 'maxLength' => 48];
        $entry = static function (string $name, string $description, string $class, array $properties, array $required = [], bool $write = false, bool $destructive = false): array {
            return [
                'name' => $name,
                'description' => $description,
                'class' => $class,
                'write' => $write,
                'destructive' => $destructive,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $properties ?: (object) [],
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ];
        };

        $specs = [
            $entry('getAllProjects', 'List projects accessible to the current user.', GetAllProjectsTool::class, ['showClosedProjects' => ['type' => 'boolean'], 'includeProgressDetails' => ['type' => 'boolean']]),
            $entry('findProject', 'Find accessible projects by name.', FindProjectTool::class, ['term' => $s(120)], ['term']),
            $entry('getProject', 'Read an accessible project and its progress.', GetProjectTool::class, ['projectId' => $i()], ['projectId']),
            $entry('addProject', 'Create a new project.', AddProjectTool::class, ['name' => $s(), 'details' => $s(5000)], ['name'], true),
            $entry('getAllGoals', 'List goals in an accessible project.', GetAllGoalsTool::class, ['projectId' => $i()], ['projectId']),
            $entry('getGoal', 'Read a goal.', GetGoalTool::class, ['goalId' => $i()], ['goalId']),
            $entry('createGoalboard', 'Create a goal board in a project.', CreateGoalboardTool::class, ['projectId' => $i(), 'title' => $s(), 'description' => $s(5000)], ['projectId', 'title'], true),
            $entry('createGoal', 'Create a goal on an accessible goal board.', CreateGoalTool::class, ['canvasId' => $i(), 'title' => $s(), 'description' => $s(5000), 'startValue' => ['type' => 'number'], 'currentValue' => ['type' => 'number'], 'endValue' => ['type' => 'number'], 'metricType' => $s(40)], ['canvasId', 'title', 'description', 'startValue', 'currentValue', 'endValue'], true),
            $entry('editGoal', 'Edit a goal.', EditGoalTool::class, ['id' => $i(), 'title' => $s(), 'description' => $s(5000), 'currentValue' => ['type' => 'number'], 'endValue' => ['type' => 'number'], 'status' => $s(40)], ['id'], true),
            $entry('findTasks', 'Search tasks in one or more accessible projects.', FindTasksTool::class, ['projectIds' => ['type' => 'array', 'items' => $i(), 'minItems' => 1, 'maxItems' => 10], 'status' => ['type' => 'string', 'enum' => ['open', 'done', 'all']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], ['projectIds']),
            $entry('getTicket', 'Read a task by ID.', GetTaskTool::class, ['id' => $i()], ['id']),
            $entry('getMilestone', 'Read a milestone by ID.', GetMilestoneTool::class, ['id' => $i()], ['id']),
            $entry('addTask', 'Create a task in an accessible project.', AddTaskTool::class, ['projectId' => $i(), 'headline' => $s(), 'description' => $s(5000), 'dateToFinish' => $date()], ['projectId', 'headline'], true),
            $entry('bulkAddTasks', 'Create up to ten related tasks atomically in one selected project.', BulkAddTasksTool::class, [
                'tasks' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [
                    'type' => 'object', 'properties' => ['projectId' => $i(), 'headline' => $s(), 'description' => $s(5000)],
                    'required' => ['projectId', 'headline'], 'additionalProperties' => false,
                ]],
            ], ['tasks'], true),
            $entry('bulkEditTasks', 'Edit up to ten accessible tasks atomically.', BulkEditTasksTool::class, [
                'updates' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [
                    'type' => 'object', 'properties' => ['id' => $i(), 'params' => [
                        'type' => 'object', 'properties' => ['headline' => $s(), 'description' => $s(5000), 'status' => ['type' => 'integer', 'minimum' => 0], 'dateToFinish' => $date()],
                        'minProperties' => 1, 'additionalProperties' => false,
                    ]], 'required' => ['id', 'params'], 'additionalProperties' => false,
                ]],
            ], ['updates'], true),
            $entry('bulkScheduleTasks', 'Schedule up to ten accessible tasks atomically.', BulkScheduleTasksTool::class, [
                'schedules' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [
                    'type' => 'object', 'properties' => ['taskId' => $i(), 'editFrom' => $date(), 'editTo' => $date()],
                    'required' => ['taskId', 'editFrom', 'editTo'], 'additionalProperties' => false,
                ]],
            ], ['schedules'], true),
            $entry('addMilestone', 'Create a milestone in an accessible project.', AddMilestoneTool::class, ['projectId' => $i(), 'headline' => $s(), 'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'], 'editFrom' => $date(), 'editTo' => $date()], ['projectId', 'headline', 'color', 'editFrom', 'editTo'], true),
            $entry('addSubtask', 'Create a subtask under an accessible task.', AddSubtaskTool::class, ['parentTicket' => $i(), 'headline' => $s(), 'description' => $s(5000)], ['parentTicket', 'headline'], true),
            $entry('editTask', 'Edit a task title, description, status, or due date.', EditTaskTool::class, ['id' => $i(), 'params' => ['type' => 'object', 'properties' => ['headline' => $s(), 'description' => $s(5000), 'status' => ['type' => 'integer', 'minimum' => 0], 'dateToFinish' => $date()], 'additionalProperties' => false, 'minProperties' => 1]], ['id', 'params'], true),
            $entry('editMilestone', 'Edit a milestone title, description, or schedule.', EditMilestoneTool::class, ['id' => $i(), 'params' => ['type' => 'object', 'properties' => ['headline' => $s(), 'description' => $s(5000), 'editFrom' => $date(), 'editTo' => $date()], 'additionalProperties' => false, 'minProperties' => 1]], ['id', 'params'], true),
            $entry('getCalendar', 'Read the current user’s calendar.', GetCalendarTool::class, ['from' => $date(), 'until' => $date()], ['from', 'until']),
            $entry('addEvent', 'Add an event to the current user’s calendar.', AddCalendarEventTool::class, ['eventTitle' => $s(), 'dateFrom' => $date(), 'dateTo' => $date(), 'allDay' => ['type' => 'boolean']], ['eventTitle', 'dateFrom', 'dateTo'], true),
            $entry('editEvent', 'Edit an event owned by the current user.', EditCalendarEventTool::class, ['id' => $i(), 'eventTitle' => $s(), 'dateFrom' => $date(), 'dateTo' => $date(), 'allDay' => ['type' => 'boolean']], ['id', 'eventTitle', 'dateFrom', 'dateTo'], true),
            $entry('deleteEvent', 'Delete an event owned by the current user.', DeleteCalendarEventTool::class, ['id' => $i()], ['id'], true, true),
            $entry('scheduleTaskOnCalendar', 'Schedule an accessible task.', ScheduleTaskOnCalendarTool::class, ['id' => $i(), 'editFrom' => $date(), 'editTo' => $date()], ['id', 'editFrom', 'editTo'], true),
            $entry('getUserTimesheets', 'Read the current user’s timesheets.', GetUserTimesheetsTool::class, ['dateFrom' => $date(), 'dateTo' => $date(), 'projectId' => $i()], ['dateFrom', 'dateTo']),
            $entry('logTime', 'Log the current user’s time against an accessible task.', LogTimeTool::class, ['ticketId' => $i(), 'hours' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24], 'date' => $date(), 'kind' => ['type' => 'string', 'enum' => ['GENERAL_BILLABLE', 'GENERAL_NOT_BILLABLE', 'PROJECTMANAGEMENT', 'DEVELOPMENT', 'BUGFIXING_NOT_BILLABLE', 'TESTING']], 'description' => $s(2000)], ['ticketId', 'hours', 'date', 'kind'], true),
            $entry('startTimer', 'Start a timer on an accessible task.', StartTimerTool::class, ['duration' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5, 'pattern' => '^(?:(?:[1-9])h)?(?:(?:[1-9]|[1-5][0-9])m)?$'], 'taskId' => $i(), 'type' => ['type' => 'string', 'enum' => ['work']]], ['duration', 'taskId'], true),
            $entry('stopTimer', 'Stop the current user’s timer.', StopTimerTool::class, ['ticketId' => $i()], [], true),
            $entry('getAllProjectComments', 'Read project status updates.', GetAllProjectCommentsTool::class, ['projectId' => $i()], ['projectId']),
            $entry('addComment', 'Add a comment to an accessible project or task.', AddCommentTool::class, ['module' => ['type' => 'string', 'enum' => ['project', 'ticket']], 'entityId' => $i(), 'text' => $s(5000)], ['module', 'entityId', 'text'], true),
            $entry('addProjectStatusUpdate', 'Post a project status update.', AddProjectStatusUpdateTool::class, ['projectId' => $i(), 'text' => $s(5000), 'status' => ['type' => 'string', 'enum' => ['green', 'yellow', 'red']]], ['projectId', 'text', 'status'], true),
        ];

        return array_column($specs, null, 'name');
    }

    /** @param array<string, mixed> $schema */
    private function validateValue(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException("Invalid {$path}.");
        }
        if ($type === 'object') {
            $properties = (array) ($schema['properties'] ?? []);
            if (count($value) < ($schema['minProperties'] ?? 0)) {
                throw new InvalidArgumentException("Invalid {$path}.");
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    throw new InvalidArgumentException("Missing {$path}.{$key}.");
                }
            }
            foreach ($value as $key => $item) {
                if (! is_string($key) || ! isset($properties[$key])) {
                    throw new InvalidArgumentException("Unknown {$path} field.");
                }
                $this->validateValue($item, $properties[$key], "{$path}.{$key}");
            }
        } elseif ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                throw new InvalidArgumentException("Invalid {$path} length.");
            }
            foreach ($value as $index => $item) {
                $this->validateValue($item, $schema['items'], "{$path}[{$index}]");
            }
        } elseif ($type === 'string') {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                throw new InvalidArgumentException("Invalid {$path} length.");
            }
            if (isset($schema['pattern']) && preg_match('/'.$schema['pattern'].'/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid {$path} format.");
            }
        } elseif (in_array($type, ['integer', 'number'], true)) {
            if ($value < ($schema['minimum'] ?? -INF) || $value <= ($schema['exclusiveMinimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) {
                throw new InvalidArgumentException("Invalid {$path} range.");
            }
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new InvalidArgumentException("Invalid {$path} value.");
        }
    }
}
