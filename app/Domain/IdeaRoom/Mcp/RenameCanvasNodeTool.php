<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class RenameCanvasNodeTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'renameCanvasNode';
    }

    public function description(): string
    {
        return 'Rename an accessible canvas node immediately and record history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)
            ->integer('nodeId')->description('Existing visible node ID.')->required()
            ->raw('title', ['type' => 'string', 'minLength' => 1, 'maxLength' => 255])->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['nodeId', 'title'], function (int $roomId) use ($arguments): array {
            $node = $this->visibleNode($roomId, self::positiveId($arguments['nodeId'] ?? null, 'nodeId'));
            $node['title'] = $arguments['title'] ?? null;

            return ['patch' => ['nodes' => [$node]], 'summary' => 'Rename canvas node: '.(string) $node['title']];
        });
    }
}
