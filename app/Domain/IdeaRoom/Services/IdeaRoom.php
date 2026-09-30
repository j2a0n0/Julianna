<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Services;

use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\Language;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Goalcanvas\Permissions\GoalcanvasPermissions;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\ProviderFactory;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Leantime\Domain\IdeaRoom\Support\Plan;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Permissions\TicketsPermissions;
use Leantime\Domain\Tickets\Services\Tickets;
use RuntimeException;

final class IdeaRoom
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly PermissionService $permissions,
        private readonly Projects $projects,
        private readonly Goalcanvas $goals,
        private readonly Tickets $tickets,
    ) {}

    public function providerConfigured(): bool
    {
        return ProviderFactory::forCurrentSession() !== null;
    }

    public function canCreateProject(): bool
    {
        return $this->permissions->currentUserCan(ProjectsPermissions::CREATE, forceGlobal: true);
    }

    /** @return list<array<string, mixed>> */
    public function accessibleProjects(): array
    {
        $projects = $this->projects->getProjectsUserHasAccessTo();
        if (! is_array($projects)) {
            return [];
        }

        return array_values(array_filter($projects, fn ($project) => is_array($project)
            && isset($project['id'])
            && $this->permissions->currentUserCan(ProjectsPermissions::VIEW, (int) $project['id'])
        ));
    }

    public function canReferenceProject(int $projectId): bool
    {
        return $projectId > 0
            && $this->permissions->currentUserCan(ProjectsPermissions::VIEW, $projectId)
            && is_array($this->projects->getProject($projectId));
    }

    /** @return list<array<string, mixed>> */
    public function myRooms(): array
    {
        return $this->rooms->listForOwner($this->userId());
    }

    /** @return array<string, mixed> */
    public function create(string $idea, ?int $projectId): array
    {
        $idea = trim($idea);
        if ($idea === '' || mb_strlen($idea) > 5000) {
            throw new InvalidArgumentException('Describe your idea in 1–5000 characters.');
        }
        if ($projectId !== null) {
            $this->permissions->authorize(ProjectsPermissions::VIEW, $projectId);
            if ($this->projects->getProject($projectId) === false) {
                throw new NotFoundException;
            }
        } elseif (! $this->canCreateProject()) {
            throw new AuthorizationException;
        }

        $title = mb_substr(preg_replace('/\s+/u', ' ', $idea) ?: $idea, 0, 120);

        return $this->rooms->transaction(function () use ($projectId, $title, $idea): array {
            $room = $this->rooms->create($this->userId(), $projectId, $title, Plan::empty());
            $this->rooms->addMessage((int) $room['id'], 'user', $idea);

            return $room;
        });
    }

    /** @return array<string, mixed> */
    public function room(int $id): array
    {
        $room = $this->rooms->find($id) ?? throw new NotFoundException;
        $this->authorizeRead($room);
        if ($room['project_id'] !== null) {
            $project = $this->projects->getProject((int) $room['project_id']);
            $room['project_name'] = is_array($project) ? ($project['name'] ?? '') : '';
        }

        return $room;
    }

    /** @return list<array<string, mixed>> */
    public function messages(int $id): array
    {
        $this->room($id);

        return $this->rooms->messages($id);
    }

    /** @param array<string, mixed> $room */
    public function canEdit(array $room): bool
    {
        return $this->canControl($room) && ! in_array($room['status'], ['approved', 'archived'], true);
    }

    /** @param array<string, mixed> $room */
    public function canChat(array $room): bool
    {
        return $this->canControl($room) && $room['status'] !== 'archived';
    }

    /** @return array<string, mixed> */
    public function updateContext(int $id, ?int $projectId, ?int $expectedVersion = null): array
    {
        if ($projectId !== null) {
            $this->permissions->authorize(ProjectsPermissions::VIEW, $projectId);
            if ($this->projects->getProject($projectId) === false) {
                throw new NotFoundException;
            }
        } elseif (! $this->canCreateProject()) {
            throw new AuthorizationException;
        }

        return $this->rooms->transaction(function () use ($id, $projectId, $expectedVersion): array {
            $room = $this->rooms->lock($id) ?? throw new NotFoundException;
            $this->authorizeRead($room);
            if ($expectedVersion !== null && (int) ($room['graph_version'] ?? 0) !== $expectedVersion) {
                throw new GraphConflictException;
            }
            if (! $this->canEdit($room)
                || $this->rooms->pendingActions($id) !== []
                || in_array($this->rooms->generation($id)['status'] ?? null, ['running', 'awaiting_confirmation'], true)) {
                throw new AuthorizationException;
            }
            $this->rooms->updateProjectContext($id, $projectId);

            return ['room' => $this->room($id)];
        });
    }

    /** @param array<string, mixed> $room */
    public function canApprove(array $room): bool
    {
        if (! $this->canEdit($room)) {
            return false;
        }

        $projectId = $room['project_id'] === null ? null : (int) $room['project_id'];

        return ($projectId === null ? $this->canCreateProject() : $this->permissions->currentUserCan(ProjectsPermissions::VIEW, $projectId))
            && ($projectId === null || (
                $this->permissions->currentUserCan(GoalcanvasPermissions::CREATE, $projectId)
                && $this->permissions->currentUserCan(GoalcanvasPermissions::EDIT, $projectId)
                && $this->permissions->currentUserCan(TicketsPermissions::CREATE, $projectId)
                && $this->permissions->currentUserCan(TicketsPermissions::EDIT, $projectId)
            ));
    }

    /** @param array<string, mixed> $room */
    public function canArchive(array $room): bool
    {
        return $this->canControl($room) && $room['status'] !== 'archived';
    }

    /** @return array<string, mixed> */
    public function send(int $id, string $content): array
    {
        $provider = ProviderFactory::forCurrentSession();
        if ($provider === null) {
            throw new InvalidArgumentException('An AI provider must be configured before chatting.');
        }
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new InvalidArgumentException('Write a message in 1–10000 characters.');
        }
        $room = $this->room($id);
        if (! $this->canEdit($room)) {
            throw new AuthorizationException;
        }

        $message = $this->rooms->addMessage($id, 'user', $content);
        $transcript = array_map(static fn (array $entry): array => [
            'role' => (string) $entry['role'],
            'content' => (string) $entry['content'],
        ], $this->rooms->messages($id));
        $projectContext = $room['project_id'] === null ? null : (string) ($room['project_name'] ?? '');
        $locale = (string) (session('usersettings.language') ?: session('companysettings.language') ?: 'en-US');
        if (! in_array($locale, Language::SUPPORTED_LANGUAGES, true)) {
            $locale = 'en-US';
        }
        $reply = $provider->respond(new ChatRequest($transcript, $room['plan'], $projectContext, $locale));

        return $this->rooms->transaction(function () use ($id, $reply, $message): array {
            $current = $this->rooms->lock($id) ?? throw new NotFoundException;
            $this->authorizeRead($current);
            if (! $this->canEdit($current)) {
                throw new AuthorizationException;
            }
            $plan = $current['plan'];
            $status = $current['status'];
            if ($reply->planPatch !== []) {
                // Keep the legacy chat path on the same revisioned write path as
                // canvas chat, MCP changes, and manual plan edits.
                $change = app(IdeaGraph::class)->applyPatch(
                    $id,
                    ['plan' => $reply->planPatch],
                    [],
                    'chat',
                    'AI plan update',
                    (int) $current['graph_version'],
                    (int) $current['plan_version'],
                );
                $plan = $change['plan'];
                $status = 'ready_for_review';
            }
            $assistant = $this->rooms->addMessage($id, 'assistant', $reply->text);

            return ['message' => $message, 'assistant' => $assistant, 'plan' => $plan, 'status' => $status];
        });
    }

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function savePlan(int $id, array $plan): array
    {
        return app(IdeaGraph::class)->replacePlan($id, $plan);
    }

    /**
     * Prepare a complete, already-accepted plan for human review. This never creates
     * projects, goals, milestones, tasks, or other workspace records.
     *
     * @return array{room: array<string, mixed>, status: string}
     */
    public function requestReview(int $id, int $expectedGraphVersion, int $expectedPlanVersion): array
    {
        return $this->rooms->transaction(function () use ($id, $expectedGraphVersion, $expectedPlanVersion): array {
            $room = $this->rooms->lock($id) ?? throw new NotFoundException;
            $this->authorizeRead($room);
            if (! $this->canEdit($room)) {
                throw new AuthorizationException;
            }
            if ((int) ($room['graph_version'] ?? 0) !== $expectedGraphVersion
                || (int) ($room['plan_version'] ?? 0) !== $expectedPlanVersion) {
                throw new GraphConflictException;
            }
            if ($this->rooms->pendingActions($id) !== []
                || in_array($this->rooms->generation($id)['status'] ?? null, ['running', 'awaiting_confirmation'], true)) {
                throw new InvalidArgumentException('Resolve workspace confirmations first.');
            }
            Plan::assertReady($room['plan'], $room['project_id'] === null);
            if ($room['status'] !== 'ready_for_review') {
                $this->rooms->markReadyForReview($id);
                $this->rooms->addEvent($id, 'approval.requested', [
                    'graphVersion' => $expectedGraphVersion,
                    'planVersion' => $expectedPlanVersion,
                ]);
            }

            return ['room' => $this->room($id), 'status' => 'ready_for_review'];
        });
    }

    /** @return array<string, mixed> */
    public function approve(int $id): array
    {
        return $this->rooms->transaction(function () use ($id): array {
            $room = $this->rooms->lock($id) ?? throw new NotFoundException;
            $this->authorizeRead($room);
            if ($room['approvalResult'] !== null) {
                return $room['approvalResult'] ?? throw new RuntimeException('Approved room has no result.');
            }
            if (! $this->canApprove($room)) {
                throw new AuthorizationException;
            }
            if ($room['status'] !== 'ready_for_review') {
                throw new InvalidArgumentException('Save and review the plan before approval.');
            }

            $plan = $room['plan'];
            $isNewProject = $room['project_id'] === null;
            Plan::assertReady($plan, $isNewProject);
            $projectId = $isNewProject
                ? $this->projects->addProject([
                    'name' => $plan['projectName'],
                    'details' => $plan['outcome'],
                    'clientId' => 0,
                    'assignedUsers' => [],
                    'psettings' => 'restricted',
                ])
                : (int) $room['project_id'];
            if (! is_int($projectId) || $projectId < 1) {
                throw new RuntimeException('Project creation failed.');
            }

            // Services enforce their own project permissions and business rules. All database
            // writes use the same connection and commit only after the room records the result.
            $boardId = (int) $this->goals->createGoalboard([
                'title' => mb_substr($plan['projectName'] ?: $plan['outcome'], 0, 255),
                'description' => $plan['outcome'],
                'author' => $this->userId(),
                'projectId' => $projectId,
            ]);
            if ($boardId < 1) {
                throw new RuntimeException('Goal board creation failed.');
            }
            $goalId = (int) $this->goals->createGoal([
                'canvasId' => $boardId,
                'box' => 'goal',
                'author' => $this->userId(),
                'title' => mb_substr($plan['outcome'], 0, 255),
                'description' => $plan['outcome'],
                'assumptions' => implode("\n", $plan['assumptions']),
            ]);
            if ($goalId < 1) {
                throw new RuntimeException('Goal creation failed.');
            }

            $milestoneIds = [];
            $taskIds = [];
            foreach ($plan['milestones'] as $milestone) {
                $milestoneId = $this->createdTicketId($this->tickets->quickAddMilestone([
                    'headline' => $milestone['title'],
                    'projectId' => $projectId,
                ]));
                if ($milestone['description'] !== '' && ! $this->tickets->patchTicket($milestoneId, ['description' => $milestone['description']])) {
                    throw new RuntimeException('Milestone description could not be saved.');
                }
                if (! $this->goals->addMilestoneToGoal($goalId, $milestoneId)) {
                    throw new RuntimeException('Milestone linking failed.');
                }
                $milestoneIds[] = $milestoneId;
                foreach ($milestone['tasks'] as $task) {
                    $taskIds[] = $this->createdTicketId($this->tickets->addTicket([
                        'headline' => $task['title'],
                        'description' => $task['description'],
                        'type' => 'task',
                        'projectId' => $projectId,
                        'milestoneid' => $milestoneId,
                    ]));
                }
            }
            foreach ($plan['tasks'] as $task) {
                $taskIds[] = $this->createdTicketId($this->tickets->addTicket([
                    'headline' => $task['title'],
                    'description' => $task['description'],
                    'type' => 'task',
                    'projectId' => $projectId,
                ]));
            }

            $result = [
                'room' => ['id' => $id, 'status' => 'approved'],
                'projectId' => $projectId,
                'goalId' => $goalId,
                'milestoneIds' => $milestoneIds,
                'taskIds' => $taskIds,
            ];
            $this->rooms->approve($id, $projectId, $goalId, $result);

            return $result;
        });
    }

    /** @return array{room: array{id: int, status: string}} */
    public function archive(int $id): array
    {
        return $this->rooms->transaction(function () use ($id): array {
            $room = $this->rooms->lock($id) ?? throw new NotFoundException;
            $this->authorizeRead($room);
            if ($room['status'] !== 'archived') {
                $this->rooms->archive($id);
            }

            return ['room' => ['id' => $id, 'status' => 'archived']];
        });
    }

    /** @param array<string, mixed> $room */
    private function authorizeRead(array $room): void
    {
        if ($room['project_id'] !== null) {
            $this->permissions->authorize(ProjectsPermissions::VIEW, (int) $room['project_id']);
        }
        if (! $this->canControl($room)) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $room */
    private function canControl(array $room): bool
    {
        return (int) $room['owner_user_id'] === $this->userId()
            || Auth::userIsAtLeast(Roles::$admin, forceGlobalRoleCheck: true)
            || ($room['project_id'] !== null && $this->projects->userCanManageProject((int) $room['project_id']));
    }

    private function userId(): int
    {
        $id = (int) session('userdata.id');
        if ($id < 1) {
            throw new AuthorizationException;
        }

        return $id;
    }

    private function createdTicketId(array|int|bool $result): int
    {
        if (! is_int($result) || $result < 1) {
            throw new RuntimeException('Task or milestone creation failed.');
        }

        return $result;
    }
}
