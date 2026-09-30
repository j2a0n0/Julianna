<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

final class SetIdeaRoomModeTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaGraph $graph, private readonly IdeaRoom $rooms) {}

    public function name(): string
    {
        return 'setIdeaRoomMode';
    }

    public function description(): string
    {
        return 'Switch the canvas to explore or execute mode immediately. The previous mode remains in Idea Room history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->required()
            ->integer('expectedVersion')->description('Current canvas version; stale versions fail.')->required()
            ->raw('mode', ['type' => 'string', 'enum' => ['explore', 'execute']])->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'expectedVersion', 'mode']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $version = $this->version($arguments);
            $mode = $arguments['mode'];
            if (! is_string($mode) || ! in_array($mode, ['explore', 'execute'], true)) {
                throw new InvalidArgumentException('Provide a valid Idea Room mode.');
            }
            $applied = $this->graph->applyPatch($roomId, ['mode' => $mode], [], 'mcp', 'Switch canvas to '.$mode.' mode', $version);

            return StructuredIdeaRoomToolResult::success('Canvas mode updated and saved in history #'.$applied['historyEntry']['id'].'.', [
                'status' => 'applied',
                'historyEntry' => $applied['historyEntry'],
                'graph' => $applied['graph'],
                'room' => $this->rooms->room($roomId),
                'url' => '/idea-room/'.$roomId,
            ]);
        });
    }
}
