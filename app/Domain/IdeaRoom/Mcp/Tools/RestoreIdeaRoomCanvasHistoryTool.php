<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;

final class RestoreIdeaRoomCanvasHistoryTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaGraph $graph) {}

    public function name(): string
    {
        return 'restoreIdeaRoomCanvasHistory';
    }

    public function description(): string
    {
        return 'Restore a canvas and plan revision immediately. The restore is recorded as a new revision, so it can itself be undone. Requires current graph and plan versions.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->description('Accessible Idea Room ID.')->required()
            ->integer('historyEntryId')->description('Revision ID returned by listIdeaRoomCanvasHistory.')->required()
            ->integer('expectedVersion')->description('Current canvas graph version.')->required()
            ->integer('expectedPlanVersion')->description('Current action-plan version.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'historyEntryId', 'expectedVersion', 'expectedPlanVersion']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $historyEntryId = $this->positiveId($arguments, 'historyEntryId');
            $graphVersion = $this->version($arguments);
            $planVersion = $this->version($arguments, 'expectedPlanVersion');
            $restored = $this->graph->restoreHistory($roomId, $historyEntryId, $graphVersion, $planVersion);

            return StructuredIdeaRoomToolResult::success('Canvas and plan restored; this restore is in the history.', [
                'status' => 'restored',
                'roomId' => $roomId,
                'restoredFromId' => $historyEntryId,
                ...$restored,
            ]);
        });
    }
}
