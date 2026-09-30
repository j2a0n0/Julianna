<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class ListIdeaRoomsTool extends AbstractIdeaRoomReadTool
{
    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly RoomRepository $rooms,
    ) {}

    public function name(): string
    {
        return 'listIdeaRooms';
    }

    public function description(): string
    {
        return 'List your accessible Idea Rooms, newest first, with bounded cursor pagination.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('beforeId')->description('Optional room ID cursor; return rooms with smaller IDs.')
            ->integer('limit')->description('Page size from 1 to 50; default 20.');
    }

    protected function allowedArguments(): array
    {
        return ['beforeId', 'limit'];
    }

    protected function read(array $arguments): array
    {
        $userId = (int) session('userdata.id');
        if ($userId < 1) {
            throw new AuthorizationException;
        }
        $limit = self::pageSize($arguments);
        $projectIds = array_map(static fn (array $project): int => (int) $project['id'], $this->ideaRooms->accessibleProjects());
        $rows = $this->rooms->listAccessibleCandidatesPage(
            $userId,
            $projectIds,
            Auth::userIsAtLeast(Roles::$admin, forceGlobalRoleCheck: true),
            self::cursor($arguments, 'beforeId'),
            $limit + 1,
        );
        $hasMore = count($rows) > $limit;
        $scanned = array_slice($rows, 0, $limit);
        $visible = [];
        foreach ($scanned as $candidate) {
            try {
                $visible[] = self::roomSummary($this->ideaRooms->room((int) $candidate['id']));
            } catch (AuthorizationException|NotFoundException) {
                // Project access may have changed since room creation.
            }
        }
        $nextCursor = $hasMore ? (int) $scanned[array_key_last($scanned)]['id'] : null;

        return [
            'summary' => count($visible).' accessible Idea Room'.(count($visible) === 1 ? '' : 's').' in this page.',
            'data' => ['rooms' => $visible, 'nextCursor' => $nextCursor, 'limit' => $limit],
        ];
    }
}
