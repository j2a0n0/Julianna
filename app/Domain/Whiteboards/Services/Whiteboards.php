<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Whiteboards\Permissions\WhiteboardsPermissions;
use Leantime\Domain\Whiteboards\Support\WhiteboardConflictException;
use Leantime\Domain\Whiteboards\Support\WhiteboardScene;

/** One service for browser editing, in-app agent actions, and MCP tools. */
final class Whiteboards
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly WhiteboardAccess $access,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listBoards(int $projectId, int $actorId): array
    {
        $this->access->authorize($projectId, $actorId, WhiteboardsPermissions::VIEW);

        return $this->db->table('julianna_whiteboards')->where('project_id', $projectId)
            ->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(static fn ($row): array => self::summary($row))->all();
    }

    /** @return array<string, mixed> */
    public function createBoard(int $projectId, int $actorId, string $title): array
    {
        $this->access->authorize($projectId, $actorId, WhiteboardsPermissions::CREATE);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '' || mb_strlen($title) > 160) {
            throw new InvalidArgumentException('Whiteboard titles must contain 1–160 characters.');
        }

        return $this->db->transaction(function () use ($projectId, $actorId, $title): array {
            $now = gmdate('Y-m-d H:i:s');
            $scene = json_encode(WhiteboardScene::empty(), JSON_THROW_ON_ERROR);
            $id = (int) $this->db->table('julianna_whiteboards')->insertGetId([
                'project_id' => $projectId,
                'title' => $title,
                'revision' => 0,
                'scene_json' => $scene,
                'created_by_user_id' => $actorId,
                'updated_by_user_id' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->table('julianna_whiteboard_revisions')->insert([
                'board_id' => $id, 'revision' => 0, 'scene_json' => $scene,
                'author_user_id' => $actorId, 'created_at' => $now,
            ]);

            return $this->board($id, $actorId);
        });
    }

    /** @return array<string, mixed> */
    public function board(int $boardId, int $actorId): array
    {
        $row = $this->find($boardId);
        $this->access->authorize((int) $row->project_id, $actorId, WhiteboardsPermissions::VIEW);

        return $this->hydrate($row);
    }

    /** @param array<string,mixed> $scene
     * @return array<string,mixed>
     */
    public function saveScene(int $boardId, int $actorId, int $expectedRevision, array $scene): array
    {
        if ($expectedRevision < 0) {
            throw new InvalidArgumentException('Provide the current Whiteboard revision.');
        }
        $validated = WhiteboardScene::normalize($scene);

        return $this->db->transaction(function () use ($boardId, $actorId, $expectedRevision, $validated): array {
            $row = $this->find($boardId, true);
            $this->access->authorize((int) $row->project_id, $actorId, WhiteboardsPermissions::EDIT);
            if ((int) $row->revision !== $expectedRevision) {
                throw new WhiteboardConflictException;
            }
            $this->persistAssets($boardId, $validated['assets']);
            $encoded = json_encode($validated['scene'], JSON_THROW_ON_ERROR);
            // MySQL JSON columns normalize object key order and whitespace, so
            // byte equality would create false revisions on unchanged scenes.
            if ($validated['scene'] == json_decode((string) $row->scene_json, true, 512, JSON_THROW_ON_ERROR)) {
                return $this->hydrate($row);
            }
            $next = $expectedRevision + 1;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('julianna_whiteboards')->where('id', $boardId)->update([
                'scene_json' => $encoded, 'revision' => $next,
                'updated_by_user_id' => $actorId, 'updated_at' => $now,
            ]);
            $this->db->table('julianna_whiteboard_revisions')->insert([
                'board_id' => $boardId, 'revision' => $next, 'scene_json' => $encoded,
                'author_user_id' => $actorId, 'created_at' => $now,
            ]);

            return $this->board($boardId, $actorId);
        });
    }

    /** @return list<array{revision:int,author_user_id:int,created_at:string}> */
    public function revisions(int $boardId, int $actorId): array
    {
        $row = $this->find($boardId);
        $this->access->authorize((int) $row->project_id, $actorId, WhiteboardsPermissions::VIEW);

        return $this->db->table('julianna_whiteboard_revisions')->where('board_id', $boardId)
            ->orderByDesc('revision')->limit(100)->get()->map(static fn ($revision): array => [
                'revision' => (int) $revision->revision,
                'author_user_id' => (int) $revision->author_user_id,
                'created_at' => (string) $revision->created_at,
            ])->all();
    }

    /** @return array<string,mixed> */
    public function revision(int $boardId, int $actorId, int $revision): array
    {
        $board = $this->find($boardId);
        $this->access->authorize((int) $board->project_id, $actorId, WhiteboardsPermissions::VIEW);
        $row = $this->db->table('julianna_whiteboard_revisions')
            ->where('board_id', $boardId)->where('revision', $revision)->first();
        if ($row === null) {
            throw new NotFoundException;
        }

        return ['revision' => $revision, 'scene' => $this->hydrateScene($boardId, (string) $row->scene_json)];
    }

    /** @return array<string,mixed> */
    public function restoreRevision(int $boardId, int $actorId, int $expectedRevision, int $sourceRevision): array
    {
        if ($expectedRevision < 0 || $sourceRevision < 0) {
            throw new InvalidArgumentException('Provide valid Whiteboard revisions.');
        }

        return $this->db->transaction(function () use ($boardId, $actorId, $expectedRevision, $sourceRevision): array {
            $board = $this->find($boardId, true);
            $this->access->authorize((int) $board->project_id, $actorId, WhiteboardsPermissions::EDIT);
            if ((int) $board->revision !== $expectedRevision) {
                throw new WhiteboardConflictException;
            }
            $source = $this->db->table('julianna_whiteboard_revisions')
                ->where('board_id', $boardId)->where('revision', $sourceRevision)->first();
            if ($source === null) {
                throw new NotFoundException;
            }
            $next = $expectedRevision + 1;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('julianna_whiteboards')->where('id', $boardId)->update([
                'scene_json' => $source->scene_json, 'revision' => $next,
                'updated_by_user_id' => $actorId, 'updated_at' => $now,
            ]);
            $this->db->table('julianna_whiteboard_revisions')->insert([
                'board_id' => $boardId, 'revision' => $next, 'scene_json' => $source->scene_json,
                'author_user_id' => $actorId, 'created_at' => $now,
            ]);

            return $this->board($boardId, $actorId);
        });
    }

    private function find(int $boardId, bool $lock = false): object
    {
        $query = $this->db->table('julianna_whiteboards')->where('id', $boardId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row ?? throw new NotFoundException;
    }

    /** @param list<array{file_id:string,mime_type:string,data_url:?string,byte_size:int}> $assets */
    private function persistAssets(int $boardId, array $assets): void
    {
        foreach ($assets as $asset) {
            $stored = $this->db->table('julianna_whiteboard_assets')
                ->where('board_id', $boardId)->where('file_id', $asset['file_id'])->first();
            if ($stored !== null) {
                if ((string) $stored->mime_type !== $asset['mime_type']
                    || ($asset['data_url'] !== null && (string) $stored->data_url !== $asset['data_url'])) {
                    throw new InvalidArgumentException('A Whiteboard image ID cannot be changed.');
                }
                continue;
            }
            if ($asset['data_url'] === null) {
                throw new InvalidArgumentException('A Whiteboard image is missing its data.');
            }
            $this->db->table('julianna_whiteboard_assets')->insert([
                'board_id' => $boardId, 'file_id' => $asset['file_id'],
                'mime_type' => $asset['mime_type'], 'data_url' => $asset['data_url'],
                'byte_size' => $asset['byte_size'], 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function hydrate(object $row): array
    {
        return self::summary($row) + ['scene' => $this->hydrateScene((int) $row->id, (string) $row->scene_json)];
    }

    /** @return array{elements:array,appState:array,files:array} */
    private function hydrateScene(int $boardId, string $encoded): array
    {
        $scene = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $files = $scene['files'] ?? [];
        if ($files !== []) {
            $assets = $this->db->table('julianna_whiteboard_assets')->where('board_id', $boardId)
                ->whereIn('file_id', array_keys($files))->get();
            foreach ($assets as $asset) {
                if (isset($files[$asset->file_id])) {
                    $files[$asset->file_id]['dataURL'] = (string) $asset->data_url;
                }
            }
        }
        $scene['files'] = $files;

        return $scene;
    }

    /** @return array<string,mixed> */
    private static function summary(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'project_id' => (int) $row->project_id,
            'title' => (string) $row->title,
            'revision' => (int) $row->revision,
            'created_by_user_id' => (int) $row->created_by_user_id,
            'updated_by_user_id' => (int) $row->updated_by_user_id,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
