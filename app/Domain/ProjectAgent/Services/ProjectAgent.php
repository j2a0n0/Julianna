<?php

declare(strict_types=1);

namespace Leantime\Domain\ProjectAgent\Services;

use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Agent\Services\Agent;
use Leantime\Domain\ProjectAgent\Repositories\ProjectAgentRepository;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use Throwable;

/**
 * Project-level autonomous-agent boundary. A queued run is never authority in
 * itself: access and enabled state are re-read immediately before each action.
 */
class ProjectAgent
{
    private static bool $agentActionInProgress = false;

    private static ?int $backgroundActorId = null;

    public function __construct(
        private readonly ProjectAgentRepository $store,
        private readonly PermissionService $permissions,
    ) {}

    /** @return array<string, mixed> */
    public function settings(int $projectId): array
    {
        $this->authorizeView($projectId);

        return $this->normalSettings($projectId, $this->store->settings($projectId));
    }

    /** @return array<string, mixed> */
    public function configure(int $projectId, bool $enabled, bool $paused = false): array
    {
        $this->authorizeView($projectId);
        $this->permissions->authorize(ProjectsPermissions::EDIT, $projectId);
        $actorId = $this->currentUserId();

        $row = $this->store->transaction(function () use ($projectId, $enabled, $paused, $actorId): array {
            $previous = $this->store->settings($projectId);
            $enabler = $enabled
                ? (($previous !== null && (bool) $previous['enabled']) ? (int) $previous['enabled_by_user_id'] : $actorId)
                : null;

            return $this->store->saveSettings($projectId, $enabled, $enabled && $paused, $enabler);
        });

        return $this->normalSettings($projectId, $row);
    }

    /** Coarse authorization for both interactive and queued work. Tool permissions still apply. */
    public function isRunnable(int $projectId, int $actorId): bool
    {
        $settings = $this->store->settings($projectId);
        $enablerId = (int) ($settings['enabled_by_user_id'] ?? 0);

        return $settings !== null
            && (bool) $settings['enabled']
            && ! (bool) $settings['paused']
            && $enablerId > 0
            && $this->store->userCanAccessProject($enablerId, $projectId)
            && $this->store->userCanAccessProject($actorId, $projectId);
    }

    /** A queued run cannot inherit a new PM's later project activation. */
    public function isBackgroundRunnable(int $projectId, int $actorId): bool
    {
        $settings = $this->store->settings($projectId);

        return $this->isRunnable($projectId, $actorId)
            && (int) ($settings['enabled_by_user_id'] ?? 0) === $actorId;
    }

    /** A live worker refreshes its lease between bounded provider calls. */
    public function heartbeat(int $runId, int $projectId, int $actorId): void
    {
        if (! $this->store->heartbeatRun($runId, $projectId, $actorId)) {
            throw new AuthorizationException('This background run is no longer active.');
        }
    }

    /**
     * Store a concise, user-visible account of an action. A pending row must
     * be committed before the effect, so a crash cannot hide an uncertain write.
     * Never pass raw prompts, transcripts, tool arguments, or provider secrets.
     *
     * @param array{action:string,rationale:string,outcome:string,status?:string,recovery?:array<string,mixed>|null,runId?:int|null,idempotencyKey?:string|null} $activity
     * @return array<string, mixed>
     */
    public function recordActivity(int $projectId, int $actorId, array $activity): array
    {
        $this->assertActor($actorId, $projectId);
        if (! $this->store->projectExists($projectId)) {
            throw new NotFoundException;
        }
        $action = trim((string) ($activity['action'] ?? ''));
        $rationale = trim((string) ($activity['rationale'] ?? ''));
        $outcome = trim((string) ($activity['outcome'] ?? ''));
        $status = (string) ($activity['status'] ?? 'completed');
        if ($action === '' || mb_strlen($action) > 128 || ! preg_match('/^[a-zA-Z0-9._:-]+$/', $action)
            || $rationale === '' || mb_strlen($rationale) > 2000
            || $outcome === '' || mb_strlen($outcome) > 2000
            || ! in_array($status, ['pending', 'completed', 'failed', 'draft'], true)) {
            throw new InvalidArgumentException('Invalid agent activity.');
        }
        $recovery = $activity['recovery'] ?? null;
        if ($recovery !== null && ($status !== 'completed' || ! $this->validRecovery($recovery))) {
            throw new InvalidArgumentException('Unsupported activity recovery.');
        }
        $runId = $activity['runId'] ?? null;
        if ($runId !== null) {
            $run = $this->store->run((int) $runId);
            if ($run === null || (int) $run['project_id'] !== $projectId) {
                throw new InvalidArgumentException('Activity run does not belong to this project.');
            }
        }
        $key = $activity['idempotencyKey'] ?? null;
        if ($key !== null && (! is_string($key) || $key === '' || strlen($key) > 255)) {
            throw new InvalidArgumentException('Invalid activity idempotency key.');
        }

        $row = $this->store->addActivity($projectId, $actorId, [
            'action' => $action,
            'rationale' => $rationale,
            'outcome' => $outcome,
            'status' => $status,
            'recovery' => $recovery,
            'run_id' => $runId,
            'idempotency_key' => $key === null ? null : hash('sha256', $projectId.'|'.$key),
        ]);

        return $this->normalActivity($row);
    }

    /**
     * Confirm the outcome of a pre-dispatch activity without reauthorizing a
     * second domain write. If the actor loses access after dispatch, the
     * already-started action must still leave an accurate activity trail.
     *
     * @param array<string,mixed>|null $recovery
     * @return array<string,mixed>
     */
    public function finishActivity(
        int $projectId,
        int $activityId,
        int $actorId,
        string $outcome,
        string $status,
        ?array $recovery = null,
    ): array {
        $outcome = trim($outcome);
        if ($projectId <= 0 || $activityId <= 0 || $actorId <= 0
            || $outcome === '' || mb_strlen($outcome) > 2000
            || ! in_array($status, ['completed', 'failed', 'draft'], true)
            || ($recovery !== null && ($status !== 'completed' || ! $this->validRecovery($recovery)))) {
            throw new InvalidArgumentException('Invalid agent activity completion.');
        }

        $row = $this->store->finishActivity($projectId, $activityId, $actorId, $outcome, $status, $recovery);
        if ($row === null) {
            throw new \RuntimeException('Pending agent activity could not be completed.');
        }

        return $this->normalActivity($row);
    }

    /** @return list<array<string, mixed>> */
    public function activities(int $projectId, int $limit = 50): array
    {
        $this->authorizeView($projectId);

        return array_map($this->normalActivity(...), $this->store->activities($projectId, max(1, min(100, $limit))));
    }

    /** @return list<array<string, mixed>> */
    public function runs(int $projectId, int $limit = 20): array
    {
        $this->authorizeView($projectId);

        return array_map($this->normalRun(...), $this->store->runs($projectId, max(1, min(100, $limit))));
    }

    /** Recover only a revision-checked first-party Whiteboard scene. */
    public function undo(int $projectId, int $activityId): array
    {
        $this->authorizeView($projectId);
        $actorId = $this->currentUserId();

        return $this->store->transaction(function () use ($projectId, $activityId, $actorId): array {
            $row = $this->store->activity($projectId, $activityId, lock: true) ?? throw new NotFoundException;
            if ($row['undone_at'] !== null) {
                return $this->normalActivity($row);
            }
            $recovery = $row['recovery_json'] === null ? null : json_decode((string) $row['recovery_json'], true);
            if ($row['status'] !== 'completed' || ! $this->validRecovery($recovery)) {
                throw new InvalidArgumentException('This activity cannot be undone.');
            }
            $board = app()->make(Whiteboards::class)->board((int) $recovery['boardId'], $actorId);
            if ((int) ($board['project_id'] ?? 0) !== $projectId) {
                throw new AuthorizationException;
            }
            app()->make(Whiteboards::class)->restoreRevision(
                (int) $recovery['boardId'],
                $actorId,
                (int) $recovery['expectedRevision'],
                (int) $recovery['revision'],
            );
            $this->store->markUndone($projectId, $activityId, $actorId);

            return $this->normalActivity($this->store->activity($projectId, $activityId) ?? throw new NotFoundException);
        });
    }

    /**
     * Insert a unique run and its queue message atomically. Replays return the
     * original run without adding another message or performing another action.
     *
     * @return array<string, mixed>|null
     */
    public function enqueueReview(int $projectId, string $trigger, string $idempotencyKey): ?array
    {
        if (! in_array($trigger, ['daily', 'event', 'manual'], true)
            || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('Invalid agent review trigger.');
        }
        $settings = $this->store->settings($projectId);
        $actorId = (int) ($settings['enabled_by_user_id'] ?? 0);
        if (! $this->isRunnable($projectId, $actorId)) {
            return null;
        }
        $key = hash('sha256', $projectId.'|'.$trigger.'|'.$idempotencyKey);

        $run = $this->store->transaction(function () use ($projectId, $actorId, $trigger, $key): array {
            $result = $this->store->createRun($projectId, $actorId, $trigger, $key);
            if ($result['created']) {
                $this->store->queueRun((int) $result['run']['id'], $projectId, $actorId);
            }

            return $result['run'];
        });

        return $this->normalRun($run);
    }

    public function enqueueDailyReviews(): int
    {
        $queued = 0;
        foreach ($this->store->activeSettings() as $row) {
            if ($this->enqueueReview((int) $row['project_id'], 'daily', gmdate('Y-m-d')) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    /** Relevant project events are deduplicated by entity and modification time. */
    public function enqueueTicketEvent(int $ticketId, string $eventType): ?array
    {
        if ($ticketId <= 0 || ! in_array($eventType, ['created', 'updated'], true)) {
            return null;
        }
        $ticket = $this->store->ticketEventContext($ticketId);
        if ($ticket === null) {
            return null;
        }

        return $this->enqueueReview($ticket['project_id'], 'event',
            'ticket:'.$ticketId.':'.$eventType.':'.$ticket['modified']);
    }

    /** Called by the existing queue worker; duplicate deliveries are harmless. */
    public function processRun(int $runId): bool
    {
        $run = $this->store->run($runId);
        if ($run === null) {
            return true;
        }
        if (! $this->store->claimRun($runId)) {
            if ($this->store->markAbandonedRun($runId)) {
                return true;
            }

            // Another worker still owns this run. Keep the queue message until
            // it finishes; retrying its writes with a new conversation is unsafe.
            return ($this->store->run($runId)['status'] ?? null) !== 'running';
        }
        $projectId = (int) $run['project_id'];
        $actorId = (int) $run['actor_user_id'];
        if (! $this->isBackgroundRunnable($projectId, $actorId)) {
            $this->store->finishRun($runId, 'skipped', 'Agent paused or project access changed.');

            return true;
        }

        $agent = app()->make(Agent::class);
        if (! $agent->providerConfigured()) {
            $this->store->finishRun($runId, 'needs_setup', 'Configure a server-side AI provider for background reviews.');

            return true;
        }
        try {
            $this->heartbeat($runId, $projectId, $actorId);
            $result = self::whileBackgroundRun($actorId, fn (): array => $agent->backgroundReview(
                $projectId, $actorId, $runId, (string) $run['trigger']
            ));
            $last = end($result['turns']);
            $interrupted = is_array($last) && ($last['interrupted'] ?? false);
            $this->store->finishRun($runId, $interrupted ? 'failed' : 'completed', $interrupted
                ? 'AI provider stopped before the review completed; inspect activity before another run.'
                : 'Review completed. See project activity and Needs your input for details.');
        } catch (Throwable) {
            // Do not put prompts, tool arguments, transcripts, or credentials in run summaries.
            $this->store->finishRun($runId, 'failed', 'Review could not be completed.');
        }

        return true;
    }

    /** Ignore events caused by the agent itself so upkeep cannot recursively enqueue itself. */
    public static function whileAgentAction(callable $action): mixed
    {
        $previous = self::$agentActionInProgress;
        self::$agentActionInProgress = true;
        try {
            return $action();
        } finally {
            self::$agentActionInProgress = $previous;
        }
    }

    private static function whileBackgroundRun(int $actorId, callable $action): mixed
    {
        $previous = self::$backgroundActorId;
        self::$backgroundActorId = $actorId;
        try {
            return self::whileAgentAction($action);
        } finally {
            self::$backgroundActorId = $previous;
        }
    }

    public static function agentActionInProgress(): bool
    {
        return self::$agentActionInProgress;
    }

    private function authorizeView(int $projectId): void
    {
        if ($projectId <= 0 || ! $this->store->projectExists($projectId)) {
            throw new NotFoundException;
        }
        $this->permissions->authorize(ProjectsPermissions::VIEW, $projectId);
    }

    private function currentUserId(): int
    {
        $userId = (int) session('userdata.id');
        if ($userId <= 0) {
            throw new AuthorizationException;
        }

        return $userId;
    }

    private function assertActor(int $actorId, int $projectId): void
    {
        $sessionActor = (int) session('userdata.id');
        if ($actorId <= 0 || ($sessionActor !== $actorId
            && (self::$backgroundActorId !== $actorId || ! $this->store->userCanAccessProject($actorId, $projectId)))) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private function normalSettings(int $projectId, ?array $row): array
    {
        return [
            'projectId' => $projectId,
            'enabled' => (bool) ($row['enabled'] ?? false),
            'paused' => (bool) ($row['paused'] ?? false),
            'enabledByUserId' => isset($row['enabled_by_user_id']) ? (int) $row['enabled_by_user_id'] : null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalRun(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'projectId' => (int) $row['project_id'],
            'actorUserId' => (int) $row['actor_user_id'],
            'trigger' => (string) $row['trigger'],
            'status' => (string) $row['status'],
            'summary' => $row['summary'] === null ? null : (string) $row['summary'],
            'createdAt' => (string) $row['created_at'],
            'startedAt' => $row['started_at'],
            'finishedAt' => $row['finished_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalActivity(array $row): array
    {
        $recovery = $row['recovery_json'] === null ? null : json_decode((string) $row['recovery_json'], true);

        return [
            'id' => (int) $row['id'],
            'projectId' => (int) $row['project_id'],
            'actorUserId' => (int) $row['actor_user_id'],
            'runId' => $row['run_id'] === null ? null : (int) $row['run_id'],
            'action' => (string) $row['action'],
            'rationale' => (string) $row['rationale'],
            'outcome' => (string) $row['outcome'],
            'status' => (string) $row['status'],
            'recoveryAvailable' => $row['status'] === 'completed'
                && $row['undone_at'] === null && $this->validRecovery($recovery),
            'createdAt' => (string) $row['created_at'],
            'undoneAt' => $row['undone_at'],
        ];
    }

    private function validRecovery(mixed $recovery): bool
    {
        return is_array($recovery)
            && ($recovery['kind'] ?? null) === 'whiteboard_revision'
            && is_int($recovery['boardId'] ?? null) && $recovery['boardId'] > 0
            && is_int($recovery['expectedRevision'] ?? null) && $recovery['expectedRevision'] > 0
            && is_int($recovery['revision'] ?? null) && $recovery['revision'] >= 0;
    }
}
