<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class AddCanvasNodeTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'addCanvasNode';
    }

    public function description(): string
    {
        return 'Add a canvas node immediately and record history. Requires the current graph version; title is at most 255 characters and content at most 10000.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)
            ->raw('clientId', ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'])
            ->raw('type', ['type' => 'string', 'enum' => CanvasToolSchemas::NODE_TYPES])->required()
            ->raw('title', ['type' => 'string', 'minLength' => 1, 'maxLength' => 255])->required()
            ->raw('content', ['type' => 'string', 'maxLength' => 10000])
            ->raw('x', ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000])->required()
            ->raw('y', ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000])->required()
            ->raw('metadata', CanvasToolSchemas::metadata());
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['clientId', 'type', 'title', 'content', 'x', 'y', 'metadata'], static function () use ($arguments): array {
            $node = [
                'clientId' => $arguments['clientId'] ?? 'mcp-'.bin2hex(random_bytes(8)),
                'type' => $arguments['type'] ?? null,
                'title' => $arguments['title'] ?? null,
                'content' => $arguments['content'] ?? '',
                'x' => $arguments['x'] ?? null,
                'y' => $arguments['y'] ?? null,
                'metadata' => $arguments['metadata'] ?? [],
            ];

            return ['patch' => ['nodes' => [$node]], 'summary' => 'Add canvas node: '.(string) ($node['title'] ?? '')];
        });
    }
}
