<?php

declare(strict_types=1);

namespace Leantime\Domain\ProjectAgent\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\ProjectAgent\Jobs\ReviewProjectJob;
use Leantime\Domain\Queue\Workers\Workers;

/** Persistence only; permission decisions live in the ProjectAgent service. */
class ProjectAgentRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    /** @return array<string, mixed>|null */
    public function settings(int $projectId): ?array
    {
        $row = $this->db->table('julianna_agent_projects')->where('project_id', $projectId)->first();

        return $row === null ? null : (array) $row;
    }

    public function projectExists(int $projectId): bool
    {
        return $this->db->table('zp_projects')->where('id', $projectId)->exists();
    }

    /** @return array<string, mixed> */
    public function saveSettings(int $projectId, bool $enabled, bool $paused, ?int $enabledByUserId): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('julianna_agent_projects')->updateOrInsert(
            ['project_id' => $projectId],
            [
                'enabled' => $enabled,
                'paused' => $paused,
                'enabled_by_user_id' => $enabledByUserId,
                'updated_at' => $now,
                'created_at' => $this->settings($projectId)['created_at'] ?? $now,
            ],
        );

        return $this->settings($projectId) ?? throw new \RuntimeException('Agent settings could not be saved.');
    }

    /** @return list<array<string, mixed>> */
    public function activeSettings(): array
    {
        return $this->db->table('julianna_agent_projects')
            ->where('enabled', true)->where('paused', false)
            ->orderBy('project_id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    /**
     * Re-read identity, account state, project and access from the database. No
     * web-session or memoized user row is trusted by a queued run.
     */
    public function userCanAccessProject(int $userId, int $projectId): bool
    {
        if ($userId <= 0 || $projectId <= 0) {
            return false;
        }
        $user = $this->db->table('zp_user')->where('id', $userId)->first();
        $account = $this->db->table('julianna_auth_accounts')->where('user_id', $userId)->first();
        $project = $this->db->table('zp_projects')->where('id', $projectId)->first();
        if ($user === null || $account === null || $project === null
            || strtolower((string) ($user->status ?? '')) !== 'a'
            || (string) ($account->state ?? '') !== 'active'
            || (string) ($project->state ?? '') === '-1') {
            return false;
        }

        if (in_array((string) ($user->role ?? ''), ['40', '50'], true)) {
            return true;
        }
        if ((string) ($project->psettings ?? '') === 'all') {
            return true;
        }
        if ((string) ($project->psettings ?? '') === 'clients'
            && (int) ($project->clientId ?? -1) === (int) ($user->clientId ?? -2)) {
            return true;
        }

        return $this->db->table('zp_relationuserproject')
            ->where('userId', $userId)->where('projectId', $projectId)->exists();
    }

    /** @return array{run: array<string, mixed>, created: bool} */
    public function createRun(int $projectId, int $actorId, string $trigger, string $key): array
    {
        $created = $this->db->table('julianna_agent_runs')->insertOrIgnore([
            'project_id' => $projectId,
            'actor_user_id' => $actorId,
            'trigger' => $trigger,
            'idempotency_key' => $key,
            'status' => 'queued',
            'summary' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'started_at' => null,
            'heartbeat_at' => null,
            'finished_at' => null,
        ]) === 1;
        $row = $this->db->table('julianna_agent_runs')->where('idempotency_key', $key)->first();
        if ($row === null) {
            throw new \RuntimeException('Agent run could not be loaded.');
        }

        return ['run' => (array) $row, 'created' => $created];
    }

    /** Enqueue a run in the existing queue in the same DB transaction as creation. */
    public function queueRun(int $runId, int $projectId, int $actorId): void
    {
        $this->db->table('zp_queue')->insertOrIgnore([
            'msghash' => sha1('project-agent-review:'.$runId),
            'channel' => Workers::DEFAULT->value,
            'userId' => $actorId,
            'subject' => ReviewProjectJob::class,
            'message' => serialize($runId),
            'thedate' => gmdate('Y-m-d H:i:s'),
            'projectId' => $projectId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function run(int $runId): ?array
    {
        $row = $this->db->table('julianna_agent_runs')->where('id', $runId)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function runs(int $projectId, int $limit): array
    {
        return $this->db->table('julianna_agent_runs')->where('project_id', $projectId)
            ->orderByDesc('id')->limit($limit)->get()->map(static fn ($row): array => (array) $row)->all();
    }

    public function claimRun(int $runId): bool
    {
        return $this->db->table('julianna_agent_runs')->where('id', $runId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'),
                'heartbeat_at' => gmdate('Y-m-d H:i:s')]) === 1;
    }

    public function heartbeatRun(int $runId, int $projectId, int $actorId): bool
    {
        return $this->db->table('julianna_agent_runs')->where('id', $runId)
            ->where('project_id', $projectId)->where('actor_user_id', $actorId)
            ->where('status', 'running')->update(['heartbeat_at' => gmdate('Y-m-d H:i:s')]) === 1;
    }

    /** Never replay an interrupted run: its earlier writes may have completed. */
    public function markAbandonedRun(int $runId): bool
    {
        return $this->db->table('julianna_agent_runs')->where('id', $runId)
            ->where('status', 'running')
            ->where(static function ($query): void {
                $cutoff = gmdate('Y-m-d H:i:s', time() - 900);
                $query->where('heartbeat_at', '<=', $cutoff)
                    ->orWhere(static function ($legacy) use ($cutoff): void {
                        $legacy->whereNull('heartbeat_at')->where('started_at', '<=', $cutoff);
                    });
            })
            ->update([
                'status' => 'needs_review',
                'summary' => 'Interrupted review may have made changes; inspect activity before rerunning.',
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]) === 1;
    }

    public function finishRun(int $runId, string $status, string $summary): void
    {
        $this->db->table('julianna_agent_runs')->where('id', $runId)->where('status', 'running')
            ->update([
                'status' => $status,
                'summary' => $summary,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /** @return array<string, mixed> */
    public function addActivity(int $projectId, int $actorId, array $activity): array
    {
        $key = $activity['idempotency_key'] ?? null;
        $values = [
            'project_id' => $projectId,
            'actor_user_id' => $actorId,
            'run_id' => $activity['run_id'] ?? null,
            'action' => $activity['action'],
            'rationale' => $activity['rationale'],
            'outcome' => $activity['outcome'],
            'status' => $activity['status'],
            'recovery_json' => $activity['recovery'] === null ? null : json_encode($activity['recovery'], JSON_THROW_ON_ERROR),
            'idempotency_key' => $key,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'undone_at' => null,
            'undone_by_user_id' => null,
        ];
        if ($key !== null) {
            $this->db->table('julianna_agent_activities')->insertOrIgnore($values);
            $row = $this->db->table('julianna_agent_activities')->where('idempotency_key', $key)->first();
        } else {
            $id = (int) $this->db->table('julianna_agent_activities')->insertGetId($values);
            $row = $this->db->table('julianna_agent_activities')->where('id', $id)->first();
        }

        return $row === null ? throw new \RuntimeException('Agent activity could not be loaded.') : (array) $row;
    }

    /**
     * Complete only the pre-dispatch row belonging to this actor. A failed
     * update leaves its original pending/uncertain account visible; it must
     * never silently create a second, misleading activity after a write.
     *
     * @param array<string,mixed>|null $recovery
     * @return array<string,mixed>|null
     */
    public function finishActivity(
        int $projectId,
        int $activityId,
        int $actorId,
        string $outcome,
        string $status,
        ?array $recovery,
    ): ?array {
        $changed = $this->db->table('julianna_agent_activities')->where('project_id', $projectId)
            ->where('id', $activityId)->where('actor_user_id', $actorId)->where('status', 'pending')
            ->update([
                'outcome' => $outcome,
                'status' => $status,
                'recovery_json' => $recovery === null ? null : json_encode($recovery, JSON_THROW_ON_ERROR),
            ]);

        return $changed === 1 ? $this->activity($projectId, $activityId) : null;
    }

    /** @return list<array<string, mixed>> */
    public function activities(int $projectId, int $limit): array
    {
        return $this->db->table('julianna_agent_activities')->where('project_id', $projectId)
            ->orderByDesc('id')->limit($limit)->get()->map(static fn ($row): array => (array) $row)->all();
    }

    /** @return array<string, mixed>|null */
    public function activity(int $projectId, int $activityId, bool $lock = false): ?array
    {
        $query = $this->db->table('julianna_agent_activities')
            ->where('project_id', $projectId)->where('id', $activityId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    public function markUndone(int $projectId, int $activityId, int $actorId): void
    {
        $this->db->table('julianna_agent_activities')->where('project_id', $projectId)
            ->where('id', $activityId)->whereNull('undone_at')->update([
                'status' => 'undone',
                'undone_at' => gmdate('Y-m-d H:i:s'),
                'undone_by_user_id' => $actorId,
            ]);
    }

    /** @return array{project_id:int,modified:string}|null */
    public function ticketEventContext(int $ticketId): ?array
    {
        $row = $this->db->table('zp_tickets')->where('id', $ticketId)->first(['projectId', 'modified']);
        if ($row === null || (int) ($row->projectId ?? 0) <= 0) {
            return null;
        }

        return ['project_id' => (int) $row->projectId, 'modified' => (string) ($row->modified ?? '')];
    }
}
