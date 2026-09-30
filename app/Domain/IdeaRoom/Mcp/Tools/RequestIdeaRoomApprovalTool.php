<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

final class RequestIdeaRoomApprovalTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaRoom $rooms) {}

    public function name(): string
    {
        return 'requestIdeaRoomApproval';
    }

    public function description(): string
    {
        return 'Check that an accepted plan is complete and hand the room to a human for review. This tool cannot approve a room or create workspace records.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->required()
            ->integer('expectedVersion')->description('Current canvas version.')->required()
            ->integer('expectedPlanVersion')->description('Current action-plan version.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'expectedVersion', 'expectedPlanVersion']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $graphVersion = $this->version($arguments);
            $planVersion = $this->version($arguments, 'expectedPlanVersion');
            $review = $this->rooms->requestReview($roomId, $graphVersion, $planVersion);

            return StructuredIdeaRoomToolResult::success('The plan is ready for human review; open the Idea Room to review and approve it.', [
                'status' => 'pending_user_review',
                'roomId' => $roomId,
                'graphVersion' => $graphVersion,
                'planVersion' => $planVersion,
                'canCallerApproveInWeb' => $this->rooms->canApprove($review['room']),
                'url' => '/idea-room/'.$roomId,
                'workspaceRecordsCreated' => false,
            ]);
        });
    }
}
