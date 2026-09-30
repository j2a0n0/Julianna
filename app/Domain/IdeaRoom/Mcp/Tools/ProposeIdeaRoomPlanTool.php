<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

final class ProposeIdeaRoomPlanTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaGraph $graph, private readonly IdeaRoom $rooms) {}

    public function name(): string
    {
        return 'proposeIdeaRoomPlan';
    }

    public function description(): string
    {
        return 'Apply a validated partial action-plan edit immediately. The previous state remains available in Idea Room history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->required()
            ->integer('expectedVersion')->description('Current canvas version.')->required()
            ->integer('expectedPlanVersion')->description('Current action-plan version.')->required()
            ->raw('planPatch', [
                'type' => 'object',
                'properties' => [
                    'projectName' => ['type' => 'string', 'maxLength' => 255],
                    'outcome' => ['type' => 'string', 'maxLength' => 5000],
                    'milestones' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object']],
                    'tasks' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                    'assumptions' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
                    'openQuestions' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => false,
                'minProperties' => 1,
            ])->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'expectedVersion', 'expectedPlanVersion', 'planPatch']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $graphVersion = $this->version($arguments);
            $planVersion = $this->version($arguments, 'expectedPlanVersion');
            $patch = $arguments['planPatch'];
            if (! is_array($patch) || $patch === [] || array_is_list($patch)) {
                throw new InvalidArgumentException('Provide a non-empty plan patch.');
            }
            $applied = $this->graph->applyPatch($roomId, ['plan' => $patch], [], 'mcp', 'Update Idea Room plan', $graphVersion, $planVersion);

            return StructuredIdeaRoomToolResult::success('Plan updated and saved in history #'.$applied['historyEntry']['id'].'.', [
                'status' => 'applied',
                'historyEntry' => $applied['historyEntry'],
                'plan' => $applied['plan'],
                'planVersion' => $applied['planVersion'],
                'room' => $this->rooms->room($roomId),
                'url' => '/idea-room/'.$roomId,
            ]);
        });
    }
}
