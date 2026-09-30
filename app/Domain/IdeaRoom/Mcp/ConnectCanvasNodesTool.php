<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class ConnectCanvasNodesTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'connectCanvasNodes';
    }

    public function description(): string
    {
        return 'Connect two visible nodes immediately with a typed link and record history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)
            ->integer('sourceId')->description('Visible source node ID.')->required()
            ->integer('targetId')->description('Visible target node ID.')->required()
            ->raw('type', ['type' => 'string', 'enum' => CanvasToolSchemas::LINK_TYPES])->required()
            ->raw('clientId', ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$']);
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['sourceId', 'targetId', 'type', 'clientId'], function (int $roomId) use ($arguments): array {
            $source = self::positiveId($arguments['sourceId'] ?? null, 'sourceId');
            $target = self::positiveId($arguments['targetId'] ?? null, 'targetId');
            $this->visibleNode($roomId, $source);
            $this->visibleNode($roomId, $target);

            return ['patch' => ['links' => [[
                'clientId' => $arguments['clientId'] ?? 'mcp-'.bin2hex(random_bytes(8)),
                'sourceId' => $source, 'targetId' => $target, 'type' => $arguments['type'] ?? null,
            ]]], 'summary' => 'Connect canvas nodes #'.$source.' and #'.$target];
        });
    }
}
