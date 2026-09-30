<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class EditCanvasNodeTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'editCanvasNode';
    }

    public function description(): string
    {
        return 'Edit an accessible canvas node immediately and record history. Requires the current graph version.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)
            ->integer('nodeId')->description('Existing visible node ID.')->required()
            ->raw('type', ['type' => 'string', 'enum' => CanvasToolSchemas::NODE_TYPES])
            ->raw('title', ['type' => 'string', 'minLength' => 1, 'maxLength' => 255])
            ->raw('content', ['type' => 'string', 'maxLength' => 10000])
            ->raw('x', ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000])
            ->raw('y', ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000])
            ->raw('metadata', CanvasToolSchemas::metadata());
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['nodeId', 'type', 'title', 'content', 'x', 'y', 'metadata'], function (int $roomId) use ($arguments): array {
            $nodeId = self::positiveId($arguments['nodeId'] ?? null, 'nodeId');
            $edits = array_intersect_key($arguments, array_flip(['type', 'title', 'content', 'x', 'y', 'metadata']));
            if ($edits === []) {
                throw new InvalidArgumentException('Provide at least one node field to edit.');
            }
            $node = array_replace($this->visibleNode($roomId, $nodeId), $edits);

            return ['patch' => ['nodes' => [$node]], 'summary' => 'Edit canvas node: '.$node['title']];
        });
    }
}
