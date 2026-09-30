<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class GetIdeaRoomHistoryTool extends AbstractIdeaRoomReadTool
{
    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly RoomRepository $rooms,
    ) {}

    public function name(): string
    {
        return 'getIdeaRoomHistory';
    }

    public function description(): string
    {
        return 'Read a bounded page of user and assistant messages in an Idea Room.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return parent::schema($schema)
            ->integer('afterId')->description('Optional message ID cursor; return later messages.')
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
        $page = $this->rooms->messagesPage($roomId, self::cursor($arguments, 'afterId'), $limit + 1);
        $hasMore = count($page) > $limit;
        $messages = array_slice($page, 0, $limit);

        return [
            'summary' => count($messages).' message'.(count($messages) === 1 ? '' : 's').' in this page of Idea Room '.$roomId.'.',
            'data' => [
                'roomId' => $roomId,
                'messages' => $messages,
                'nextCursor' => $hasMore ? $messages[array_key_last($messages)]['id'] : null,
                'limit' => $limit,
            ],
        ];
    }
}
