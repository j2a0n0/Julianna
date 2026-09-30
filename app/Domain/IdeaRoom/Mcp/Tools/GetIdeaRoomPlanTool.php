<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

#[IsReadOnly]
final class GetIdeaRoomPlanTool extends AbstractIdeaRoomReadTool
{
    public function __construct(private readonly IdeaRoom $ideaRooms) {}

    public function name(): string
    {
        return 'getIdeaRoomPlan';
    }

    public function description(): string
    {
        return 'Read the current Idea Room plan and room status.';
    }

    protected function read(array $arguments): array
    {
        $room = $this->ideaRooms->room(self::roomId($arguments));
        $plan = $room['plan'];

        return [
            'summary' => 'Plan for Idea Room '.$room['id'].' has '.count($plan['milestones'] ?? []).' milestones and '.count($plan['tasks'] ?? []).' top-level tasks.',
            'data' => [
                'roomId' => (int) $room['id'],
                'status' => (string) $room['status'],
                'updatedAt' => (string) ($room['updated_at'] ?? ''),
                'plan' => $plan,
            ],
        ];
    }
}
