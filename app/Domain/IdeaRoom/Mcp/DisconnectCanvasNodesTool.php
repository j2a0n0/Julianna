<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class DisconnectCanvasNodesTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'disconnectCanvasNodes';
    }

    public function description(): string
    {
        return 'Remove an accessible canvas link immediately and record history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)->integer('linkId')->description('Visible link ID to remove.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['linkId'], function (int $roomId) use ($arguments): array {
            $linkId = self::positiveId($arguments['linkId'] ?? null, 'linkId');
            foreach ($this->graph->graph($roomId)['graph']['links'] as $link) {
                if ($link['id'] === $linkId) {
                    return ['patch' => ['removeLinkIds' => [$linkId]], 'summary' => 'Disconnect canvas link #'.$linkId];
                }
            }

            throw new InvalidArgumentException('The canvas link is not accessible.');
        });
    }
}
