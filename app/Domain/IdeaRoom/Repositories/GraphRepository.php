<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Repositories;

use Illuminate\Database\ConnectionInterface;

final class GraphRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback, 3);
    }

    /** @return array<string, mixed>|null */
    public function lockRoom(int $roomId): ?array
    {
        $room = $this->db->table('julianna_idea_rooms')->where('id', $roomId)->lockForUpdate()->first();

        return $room === null ? null : (array) $room;
    }

    /** @return list<array<string, mixed>> */
    public function roomCandidates(int $exceptRoomId): array
    {
        return $this->db->table('julianna_idea_rooms')->where('id', '<>', $exceptRoomId)
            ->where('status', '<>', 'archived')->orderByDesc('updated_at')->limit(200)->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'title' => (string) $row->title])->all();
    }

    /** @return list<array<string, mixed>> */
    public function nodes(int $roomId): array
    {
        return $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereNull('deleted_at')->orderBy('id')->get()
            ->map(static fn ($row): array => self::nodeFromRow($row))->all();
    }

    /** @return list<array<string, mixed>> */
    public function deletedNodes(int $roomId): array
    {
        return $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereNotNull('deleted_at')->orderByDesc('id')->limit(100)->get()
            ->map(static fn ($row): array => self::nodeFromRow($row))->all();
    }

    /** @return array<string, mixed>|null */
    public function deletedNode(int $roomId, int $nodeId): ?array
    {
        $row = $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->where('id', $nodeId)->whereNotNull('deleted_at')->first();

        return $row === null ? null : self::nodeFromRow($row);
    }

    /** @return array<string, mixed> */
    private static function nodeFromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'title' => (string) $row->title,
            'content' => (string) $row->content,
            'x' => (float) $row->position_x,
            'y' => (float) $row->position_y,
            'metadata' => json_decode((string) $row->metadata_json, true, 512, JSON_THROW_ON_ERROR),
            'authorUserId' => (int) $row->author_user_id,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function links(int $roomId): array
    {
        return $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->whereNull('deleted_at')->orderBy('id')->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'sourceId' => (int) $row->source_node_id,
                'targetId' => (int) $row->target_node_id,
                'type' => (string) $row->type,
            ])->all();
    }

    /** @param array<string, mixed> $node */
    public function putNode(int $roomId, array $node, int $authorId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'type' => $node['type'],
            'title' => $node['title'],
            'content' => $node['content'],
            'position_x' => $node['x'],
            'position_y' => $node['y'],
            'metadata_json' => json_encode($node['metadata'], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];
        if ($node['id'] !== null) {
            $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->where('id', $node['id'])->update($values);

            return (int) $node['id'];
        }

        return (int) $this->db->table('julianna_idea_graph_nodes')->insertGetId($values + [
            'room_id' => $roomId,
            'author_user_id' => $authorId,
            'created_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $link */
    public function putLink(int $roomId, array $link): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'source_node_id' => $link['sourceId'],
            'target_node_id' => $link['targetId'],
            'type' => $link['type'],
            'updated_at' => $now,
        ];
        if ($link['id'] !== null) {
            $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->where('id', $link['id'])->update($values);

            return (int) $link['id'];
        }

        return (int) $this->db->table('julianna_idea_graph_links')->insertGetId($values + [
            'room_id' => $roomId,
            'created_at' => $now,
        ]);
    }

    /** @param list<int> $ids */
    public function deleteLinks(int $roomId, array $ids): void
    {
        if ($ids !== []) {
            $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->whereIn('id', $ids)->whereNull('deleted_at')
                ->update(['deleted_at' => gmdate('Y-m-d H:i:s'), 'deleted_with_node' => false]);
        }
    }

    /** @param list<int> $ids */
    public function deleteNodes(int $roomId, array $ids): void
    {
        if ($ids !== []) {
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->whereNull('deleted_at')
                ->where(function ($query) use ($ids): void {
                    $query->whereIn('source_node_id', $ids)->orWhereIn('target_node_id', $ids);
                })->update(['deleted_at' => $now, 'deleted_with_node' => true]);
            $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereIn('id', $ids)->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @param list<int> $ids */
    public function restoreNodes(int $roomId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereIn('id', $ids)->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->whereNotNull('deleted_at')->where('deleted_with_node', true)
            ->whereIn('source_node_id', function ($query) use ($roomId): void {
                $query->select('id')->from('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereNull('deleted_at');
            })->whereIn('target_node_id', function ($query) use ($roomId): void {
                $query->select('id')->from('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereNull('deleted_at');
            })->update(['deleted_at' => null, 'deleted_with_node' => false]);
    }

    public function bumpVersion(int $roomId): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->increment('graph_version');
    }

    public function setMode(int $roomId, string $mode): void
    {
        $this->db->table('julianna_idea_rooms')->where('id', $roomId)->update(['mode' => $mode, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $roomId): array
    {
        return $this->db->table('julianna_idea_history')->where('room_id', $roomId)
            ->select('id', 'author_user_id', 'origin', 'summary', 'graph_version', 'plan_version', 'created_at')
            ->orderByDesc('id')->get()
            ->map(static fn ($row): array => self::historyMetadata($row))->all();
    }

    /** @return array<string, mixed>|null */
    public function latestHistory(int $roomId): ?array
    {
        $row = $this->db->table('julianna_idea_history')->where('room_id', $roomId)->orderByDesc('id')->first();

        return $row === null ? null : self::historyMetadata($row);
    }

    /** @return array<string, mixed>|null */
    public function historyEntry(int $roomId, int $entryId): ?array
    {
        $row = $this->db->table('julianna_idea_history')->where('room_id', $roomId)->where('id', $entryId)->first();
        if ($row === null) {
            return null;
        }

        return self::historyMetadata($row) + [
            'graph' => json_decode((string) $row->graph_json, true, 512, JSON_THROW_ON_ERROR),
            'plan' => json_decode((string) $row->plan_json, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, mixed> $graph @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function addHistory(int $roomId, int $authorId, string $origin, string $summary, int $graphVersion, int $planVersion, array $graph, array $plan): array
    {
        $id = (int) $this->db->table('julianna_idea_history')->insertGetId([
            'room_id' => $roomId,
            'author_user_id' => $authorId,
            'origin' => $origin,
            'summary' => mb_substr($summary, 0, 255),
            'graph_version' => $graphVersion,
            'plan_version' => $planVersion,
            'graph_json' => json_encode($graph, JSON_THROW_ON_ERROR),
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $entry = $this->historyEntry($roomId, $id) ?? throw new \RuntimeException('The history entry could not be loaded.');
        unset($entry['graph'], $entry['plan']);

        return $entry;
    }

    /** Restore a complete, already-authorized snapshot using original row IDs.
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $links
     */
    public function restoreGraphSnapshot(int $roomId, array $nodes, array $links, int $authorId): void
    {
        $nodeIds = array_column($nodes, 'id');
        $linkIds = array_column($links, 'id');
        $knownNodeIds = $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->whereIn('id', $nodeIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $knownLinkIds = $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->whereIn('id', $linkIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (count($knownNodeIds) !== count($nodeIds) || count($knownLinkIds) !== count($linkIds)) {
            throw new \InvalidArgumentException('The history snapshot refers to missing canvas elements.');
        }

        $activeLinkIds = array_column($this->links($roomId), 'id');
        $this->deleteLinks($roomId, array_values(array_diff($activeLinkIds, $linkIds)));
        $activeNodeIds = array_column($this->nodes($roomId), 'id');
        $this->deleteNodes($roomId, array_values(array_diff($activeNodeIds, $nodeIds)));
        foreach ($nodes as $node) {
            $this->putNode($roomId, $node, $authorId);
            $this->db->table('julianna_idea_graph_nodes')->where('room_id', $roomId)->where('id', $node['id'])
                ->update(['deleted_at' => null]);
        }
        foreach ($links as $link) {
            $this->putLink($roomId, $link);
            $this->db->table('julianna_idea_graph_links')->where('room_id', $roomId)->where('id', $link['id'])
                ->update(['deleted_at' => null, 'deleted_with_node' => false]);
        }
    }

    /** @return array<string, mixed> */
    private static function historyMetadata(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'authorUserId' => (int) $row->author_user_id,
            'origin' => (string) $row->origin,
            'summary' => (string) $row->summary,
            'graphVersion' => (int) $row->graph_version,
            'planVersion' => (int) $row->plan_version,
            'createdAt' => (string) $row->created_at,
        ];
    }

    /** @param array<string, mixed> $citation
     * @return array<string, mixed>
     */
    public function addSource(int $roomId, array $citation): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = $citation + ['room_id' => $roomId, 'created_at' => $now];
        $values['room_id'] = $roomId;
        $values['created_at'] = $now;
        $id = (int) $this->db->table('julianna_idea_sources')->insertGetId($values);

        return ['id' => $id] + $values;
    }

    /** @return array<string, mixed>|null */
    public function source(int $roomId, int $sourceId): ?array
    {
        $row = $this->db->table('julianna_idea_sources')->where('room_id', $roomId)->where('id', $sourceId)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function sources(int $roomId): array
    {
        return $this->db->table('julianna_idea_sources')->where('room_id', $roomId)->orderByDesc('id')->limit(100)->get()
            ->map(static fn ($row): array => (array) $row)->all();
    }

    /** @param array<string, mixed> $patch
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    public function addProposal(int $roomId, int $authorId, int $graphVersion, array $patch, array $cards, string $origin = 'chat', int $planVersion = 0, string $summary = ''): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_idea_proposals')->insertGetId([
            'room_id' => $roomId,
            'author_user_id' => $authorId,
            'status' => 'pending',
            'origin' => $origin,
            'summary' => $summary,
            'graph_version' => $graphVersion,
            'plan_version' => $planVersion,
            'patch_json' => json_encode($patch, JSON_THROW_ON_ERROR),
            'inspiration_cards_json' => json_encode($cards, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->proposal($roomId, $id) ?? throw new \RuntimeException('The proposal could not be loaded.');
    }

    /** @return array<string, mixed>|null */
    public function proposal(int $roomId, int $proposalId, bool $lock = false): ?array
    {
        $query = $this->db->table('julianna_idea_proposals')->where('room_id', $roomId)->where('id', $proposalId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : self::proposalFromRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function proposals(int $roomId, int $beforeId = 0, int $limit = 100): array
    {
        $query = $this->db->table('julianna_idea_proposals')->where('room_id', $roomId);
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        return $query->orderByDesc('id')->limit(min(max($limit, 1), 100))->get()
            ->map(static fn ($row): array => self::proposalFromRow($row))->all();
    }

    public function setProposalStatus(int $roomId, int $proposalId, string $status): void
    {
        $this->db->table('julianna_idea_proposals')->where('room_id', $roomId)->where('id', $proposalId)
            ->update(['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    private static function proposalFromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'room_id' => (int) $row->room_id,
            'status' => (string) $row->status,
            'origin' => (string) $row->origin,
            'summary' => (string) $row->summary,
            'graph_version' => (int) $row->graph_version,
            'plan_version' => (int) $row->plan_version,
            'patch' => json_decode((string) $row->patch_json, true, 512, JSON_THROW_ON_ERROR),
            'inspiration_cards' => json_decode((string) $row->inspiration_cards_json, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (string) $row->created_at,
        ];
    }
}
