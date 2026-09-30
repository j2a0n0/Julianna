<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class GetIdeaRoomTool extends AbstractIdeaRoomReadTool
{
    public function __construct(private readonly IdeaRoom $ideaRooms) {}

    public function name(): string
    {
        return 'getIdeaRoom';
    }

    public function description(): string
    {
        return 'Get an Idea Room and your current edit and chat permissions.';
    }

    protected function read(array $arguments): array
    {
        $room = $this->ideaRooms->room(self::roomId($arguments));

        return [
            'summary' => 'Idea Room '.$room['id'].': '.$room['title'].' ('.$room['status'].').',
            'data' => [
                'room' => self::roomSummary($room),
                'canEdit' => $this->ideaRooms->canEdit($room),
                'canChat' => $this->ideaRooms->canChat($room),
            ],
        ];
    }
}
