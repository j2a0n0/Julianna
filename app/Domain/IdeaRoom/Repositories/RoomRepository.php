<?php

namespace Leantime\Domain\IdeaRoom\Repositories;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use Throwable;

final class RoomRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function create(int $ownerId, ?int $projectId, string $title, array $plan): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_idea_rooms')->insertGetId([
            'owner_user_id' => $ownerId,
            'project_id' => $projectId,
            'title' => $title,
            'status' => 'active',
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('The idea room could not be loaded.');
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(int $ownerId): array
    {
        return $this->db->table('julianna_idea_rooms')
            ->where('owner_user_id', $ownerId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($row) => $this->normalize($row))
            ->all();
    }

    /** @return list<array{id: int}> */
    public function listAccessibleCandidatesPage(int $ownerId, array $projectIds, bool $globalAdmin, int $beforeId, int $limit): array
    {
        $query = $this->db->table('julianna_idea_rooms');
        if (! $globalAdmin) {
            $query->where(function ($query) use ($ownerId, $projectIds): void {
                $query->where('owner_user_id', $ownerId);
                if ($projectIds !== []) {
                    $query->orWhereIn('project_id', $projectIds);
                }
            });
        }
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        return $query->select('id')->orderByDesc('id')->limit($limit)->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id])->all();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('julianna_idea_rooms')->where('id', $id)->first();

        return $row === null ? null : $this->normalize($row);
    }

    /** @return array<string, mixed>|null */
    public function lock(int $id): ?array
    {
        $row = $this->db->table('julianna_idea_rooms')->where('id', $id)->lockForUpdate()->first();

        return $row === null ? null : $this->normalize($row);
    }

    /** @return list<array<string, mixed>> */
    public function messages(int $roomId): array
    {
        return $this->db->table('julianna_idea_messages')
            ->where('room_id', $roomId)
            ->orderBy('id')
            ->get()
            ->map(static function ($row): array {
                $message = (array) $row;
                $message['metadata'] = $message['metadata_json'] === null
                    ? null : json_decode((string) $message['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
                unset($message['metadata_json']);

                return $message;
            })
            ->all();
    }

    /** @return list<array{id: int, role: string, content: string, createdAt: string}> */
    public function messagesPage(int $roomId, int $afterId, int $limit): array
    {
        return $this->db->table('julianna_idea_messages')
            ->where('room_id', $roomId)->where('id', '>', $afterId)
            ->whereIn('role', ['user', 'assistant'])->select('id', 'role', 'content', 'created_at')
            ->orderBy('id')->limit($limit)->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'role' => (string) $row->role,
                'content' => (string) $row->content,
                'createdAt' => (string) $row->created_at,
            ])->all();
    }

    /** @return array<string, mixed> */
    public function addMessage(int $roomId, string $role, string $content, ?array $metadata = null): array
    {
        $createdAt = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_idea_messages')->insertGetId([
            'room_id' => $roomId,
            'role' => $role,
            'content' => $content,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ]);

        return ['id' => $id, 'room_id' => $roomId, 'role' => $role, 'content' => $content, 'metadata' => $metadata, 'created_at' => $createdAt];
    }

    /** @return array<string, mixed> */
    public function addEvent(int $roomId, string $name, array $payload): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_idea_events')->insertGetId([
            'room_id' => $roomId,
            'event_name' => $name,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return ['id' => $id, 'event' => $name, 'data' => $payload];
    }

    /** @return list<array<string, mixed>> */
    public function events(int $roomId, int $afterId = 0): array
    {
        return $this->db->table('julianna_idea_events')->where('room_id', $roomId)
            ->where('id', '>', $afterId)->orderBy('id')->limit(200)->get()
            ->map(static fn ($row): array => self::normalizeEvent($row))->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentEvents(int $roomId): array
    {
        return $this->db->table('julianna_idea_events')->where('room_id', $roomId)
            ->orderByDesc('id')->limit(200)->get()->reverse()->values()
            ->map(static fn ($row): array => self::normalizeEvent($row))->all();
    }

    /** @return array<string, mixed> */
    public function addAction(
        int $roomId,
        string $callId,
        string $name,
        array $arguments,
        bool $destructive,
        string $status = 'pending',
        ?string $idempotencyKey = null,
    ): array
    {
        if (! in_array($status, ['pending', 'draft', 'executing'], true)) {
            throw new \InvalidArgumentException('Invalid action status.');
        }
        $idempotencyKey ??= bin2hex(random_bytes(32));
        $existing = $this->db->table('julianna_idea_actions')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->normalizeAction($existing) + ['_created' => false];
        }
        $now = gmdate('Y-m-d H:i:s');
        try {
            $id = (int) $this->db->table('julianna_idea_actions')->insertGetId([
                'room_id' => $roomId,
                'tool_call_id' => $callId,
                'tool_name' => $name,
                'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
                'status' => $status,
                'destructive' => $destructive,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $error) {
            // A concurrent retry may have inserted this receipt. Never execute it twice.
            $existing = $this->db->table('julianna_idea_actions')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->normalizeAction($existing) + ['_created' => false];
            }
            throw $error;
        }

        return ($this->action($roomId, $id) ?? throw new \RuntimeException('Action could not be loaded.')) + ['_created' => true];
    }

    /** @return array<string, mixed>|null */
    public function action(int $roomId, int $actionId, bool $lock = false): ?array
    {
        $query = $this->db->table('julianna_idea_actions')->where('room_id', $roomId)->where('id', $actionId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : $this->normalizeAction($row);
    }

    /** @return list<array<string, mixed>> */
    public function pendingActions(int $roomId): array
    {
        return $this->db->table('julianna_idea_actions')->where('room_id', $roomId)->where('status', 'pending')
            ->orderBy('id')->get()->map(fn ($row): array => $this->normalizeAction($row))->all();
    }

    /** @return list<array<string, mixed>> */
    public function actionsByStatus(int $roomId, string $status): array
    {
        return $this->db->table('julianna_idea_actions')->where('room_id', $roomId)->where('status', $status)
            ->orderBy('id')->get()->map(fn ($row): array => $this->normalizeAction($row))->all();
    }

    /**
     * An old confirmation is never silently promoted into an autonomous write.
     * The caller records a matching tool result so the conversation remains valid.
     *
     * @return list<array<string, mixed>>
     */
    public function stalePendingActions(int $roomId): array
    {
        $actions = $this->pendingActions($roomId);
        foreach ($actions as $action) {
            $this->updateAction($roomId, (int) $action['id'], 'stale', [
                'ok' => false,
                'text' => 'This old approval was not executed. Rerun the request if it is still wanted.',
            ]);
        }

        return $actions;
    }

    /** @return list<array<string, mixed>> */
    public function projectActionsByStatus(int $projectId, string $status, int $limit = 50): array
    {
        return $this->db->table('julianna_idea_actions as actions')
            ->join('julianna_idea_rooms as rooms', 'rooms.id', '=', 'actions.room_id')
            ->where('rooms.project_id', $projectId)->where('actions.status', $status)
            ->select('actions.*')->orderByDesc('actions.id')->limit(max(1, min($limit, 100)))
            ->get()->map(fn ($row): array => $this->normalizeAction($row))->all();
    }

    /** @return list<array{id: int, tool_name: string, status: string, destructive: bool, expires_at: string, created_at: string}> */
    public function pendingActionsPage(int $roomId, int $afterId, int $limit): array
    {
        return $this->db->table('julianna_idea_actions')->where('room_id', $roomId)
            ->where('status', 'pending')->where('id', '>', $afterId)
            ->select('id', 'tool_name', 'status', 'destructive', 'expires_at', 'created_at')
            ->orderBy('id')->limit($limit)->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'tool_name' => (string) $row->tool_name,
                'status' => (string) $row->status,
                'destructive' => (bool) $row->destructive,
                'expires_at' => (string) $row->expires_at,
                'created_at' => (string) $row->created_at,
            ])->all();
    }

    public function updateAction(int $roomId, int $actionId, string $status, ?array $result = null): void
    {
        $this->db->table('julianna_idea_actions')->where('room_id', $roomId)->where('id', $actionId)->update([
            'status' => $status,
            'result_json' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setGeneration(int $roomId, string $status, bool $cancelRequested = false, bool $resetTurns = false): void
    {
        $values = [
            'status' => $status,
            'cancel_requested' => $cancelRequested,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($resetTurns) {
            $values['tool_turns'] = 0;
        }
        $this->db->table('julianna_idea_generations')->updateOrInsert(['room_id' => $roomId], $values);
    }

    public function incrementToolTurns(int $roomId): void
    {
        $this->db->table('julianna_idea_generations')->where('room_id', $roomId)->increment('tool_turns');
    }

    public function generation(int $roomId): ?array
    {
        $row = $this->db->table('julianna_idea_generations')->where('room_id', $roomId)->first();

        return $row === null ? null : (array) $row;
    }

    public function requestCancel(int $roomId): void
    {
        $this->db->table('julianna_idea_generations')->where('room_id', $roomId)->update([
            'cancel_requested' => true,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalizeAction(object $row): array
    {
        $action = (array) $row;
        $action['arguments'] = json_decode((string) $action['arguments_json'], true, 512, JSON_THROW_ON_ERROR);
        $action['result'] = $action['result_json'] === null ? null : json_decode((string) $action['result_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($action['arguments_json'], $action['result_json'], $action['idempotency_key']);

        return $action;
    }

    /** @return array<string, mixed> */
    private static function normalizeEvent(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'event' => (string) $row->event_name,
            'data' => json_decode((string) $row->payload_json, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function updatePlan(int $roomId, array $plan, string $status): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'status' => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->increment('plan_version');
    }

    public function updateProjectContext(int $roomId, ?int $projectId): void
    {
        $current = $this->db->table('julianna_idea_rooms')->where('id', $roomId)->value('project_id');
        if (($current === null ? null : (int) $current) === $projectId) {
            return;
        }
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'project_id' => $projectId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->increment('graph_version');
        $version = (int) $this->db->table('julianna_idea_rooms')->where('id', $roomId)->value('graph_version');
        $this->addEvent($roomId, 'graph.updated', ['version' => $version]);
    }

    public function hasPendingProposals(int $roomId): bool
    {
        return $this->db->table('julianna_idea_proposals')->where('room_id', $roomId)
            ->where('status', 'pending')->exists();
    }

    public function markReadyForReview(int $roomId): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'status' => 'ready_for_review',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function approve(int $roomId, int $projectId, int $goalId, array $result): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'status' => 'approved',
            'approved_project_id' => $projectId,
            'approved_goal_id' => $goalId,
            'approval_result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'approved_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function archive(int $roomId): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'status' => 'archived',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback, 3);
    }

    /** @return array<string, mixed> */
    private function normalize(object $row): array
    {
        $room = (array) $row;
        try {
            $room['plan'] = json_decode((string) $room['plan_json'], true, 512, JSON_THROW_ON_ERROR);
            $room['approvalResult'] = $room['approval_result_json'] === null
                ? null
                : json_decode((string) $room['approval_result_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('The saved idea room contains invalid plan data.', previous: $error);
        }
        unset($room['plan_json'], $room['approval_result_json']);
        $room['plan_version'] = (int) ($room['plan_version'] ?? 0);

        return $room;
    }
}
