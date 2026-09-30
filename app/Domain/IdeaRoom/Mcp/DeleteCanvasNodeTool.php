<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class DeleteCanvasNodeTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'deleteCanvasNode';
    }

    public function description(): string
    {
        return 'Delete a visible node and its links immediately. The node remains recoverable through history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)->integer('nodeId')->description('Visible node ID to delete.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['nodeId'], function (int $roomId) use ($arguments): array {
            $node = $this->visibleNode($roomId, self::positiveId($arguments['nodeId'] ?? null, 'nodeId'));

            return ['patch' => ['removeNodeIds' => [$node['id']]], 'summary' => 'Delete canvas node: '.$node['title']];
        });
    }
}
