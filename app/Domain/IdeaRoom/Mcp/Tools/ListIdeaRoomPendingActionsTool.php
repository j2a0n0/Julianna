<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class ListIdeaRoomPendingActionsTool extends AbstractIdeaRoomReadTool
{
    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly RoomRepository $rooms,
    ) {}

    public function name(): string
    {
        return 'listIdeaRoomPendingActions';
    }

    public function description(): string
    {
        return 'List workspace actions awaiting the Idea Room user’s confirmation.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return parent::schema($schema)
            ->integer('afterId')->description('Optional action ID cursor; return later pending actions.')
            ->integer('limit')->description('Page size from 1 to 50; default 20.');
    }

    protected function allowedArguments(): array
    {
        return ['roomId', 'afterId', 'limit'];
    }

    protected function read(array $arguments): array
    {
        $roomId = self::roomId($arguments);
        $this->ideaRooms->room($roomId);
        $limit = self::pageSize($arguments);
        $page = $this->rooms->pendingActionsPage($roomId, self::cursor($arguments, 'afterId'), $limit + 1);
        $hasMore = count($page) > $limit;
        $actions = array_map(static fn (array $action): array => [
            'id' => (int) $action['id'],
            'toolName' => (string) $action['tool_name'],
            'status' => (string) $action['status'],
            'destructive' => (bool) $action['destructive'],
            'expiresAt' => (string) $action['expires_at'],
            'createdAt' => (string) $action['created_at'],
        ], array_slice($page, 0, $limit));

        return [
            'summary' => count($actions).' pending action'.(count($actions) === 1 ? '' : 's').' in this page.',
            'data' => [
                'roomId' => $roomId,
                'actions' => $actions,
                'nextCursor' => $hasMore ? $actions[array_key_last($actions)]['id'] : null,
                'limit' => $limit,
            ],
        ];
    }
}
