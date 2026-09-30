<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class RestoreCanvasNodeTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'restoreCanvasNode';
    }

    public function description(): string
    {
        return 'Restore a deleted canvas node with its original ID and eligible links immediately; record the change in history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)->integer('nodeId')->description('Recoverable deleted node ID.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['nodeId'], static function () use ($arguments): array {
            $nodeId = self::positiveId($arguments['nodeId'] ?? null, 'nodeId');

            return ['patch' => ['restoreNodeIds' => [$nodeId]], 'summary' => 'Restore canvas node #'.$nodeId];
        });
    }
}
