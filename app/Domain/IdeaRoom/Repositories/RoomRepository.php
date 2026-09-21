<?php

namespace Leantime\Domain\IdeaRoom\Repositories;

use Illuminate\Database\ConnectionInterface;
use JsonException;

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
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, mixed> */
    public function addMessage(int $roomId, string $role, string $content): array
    {
        $createdAt = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_idea_messages')->insertGetId([
            'room_id' => $roomId,
            'role' => $role,
            'content' => $content,
            'created_at' => $createdAt,
        ]);

        return ['id' => $id, 'room_id' => $roomId, 'role' => $role, 'content' => $content, 'created_at' => $createdAt];
    }

    public function updatePlan(int $roomId, array $plan, string $status): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update([
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'status' => $status,
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

        return $room;
    }
}
