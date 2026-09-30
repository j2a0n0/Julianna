<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class GetIdeaRoomStateTool extends AbstractIdeaRoomReadTool
{
    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly RoomRepository $rooms,
        private readonly IdeaGraph $graph,
    ) {}

    public function name(): string
    {
        return 'getIdeaRoomState';
    }

    public function description(): string
    {
        return 'Get current room status, graph and plan versions, generation status, workspace confirmations, and canvas history.';
    }

    protected function read(array $arguments): array
    {
        $roomId = self::roomId($arguments);
        $room = $this->ideaRooms->room($roomId);
        $generation = $this->rooms->generation($roomId);
        $actions = $this->rooms->pendingActionsPage($roomId, 0, 20);
        $history = $this->graph->history($roomId);

        return [
            'summary' => 'Idea Room '.$roomId.' is '.$room['status'].' in '.$room['mode'].' mode; graph version '.($room['graph_version'] ?? 0).'.',
            'data' => [
                'room' => self::roomSummary($room),
                'generation' => $generation === null ? null : [
                    'status' => (string) $generation['status'],
                    'toolTurns' => (int) ($generation['tool_turns'] ?? 0),
                    'cancelRequested' => (bool) ($generation['cancel_requested'] ?? false),
                ],
                'pendingActionIds' => array_column($actions, 'id'),
                'workspaceConfirmationsTruncated' => count($actions) === 20,
                'historyCount' => count($history),
                'currentHistoryEntry' => $history[0] ?? null,
            ],
        ];
    }
}
