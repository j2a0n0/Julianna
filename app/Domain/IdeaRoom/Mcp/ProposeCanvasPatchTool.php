<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

final class ProposeCanvasPatchTool extends CanvasMutationTool
{
    public function name(): string
    {
        return 'proposeCanvasPatch';
    }

    public function description(): string
    {
        return 'Apply canvas nodes, links, removals, restorations, mode, or plan changes immediately; the previous state remains in history.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->baseSchema($schema)
            ->integer('expectedPlanVersion')->description('Required when patch.plan is non-empty; prevents overwriting newer plan changes.')
            ->raw('patch', CanvasToolSchemas::patch())->required()
            ->raw('inspirationCards', ['type' => 'array', 'maxItems' => 12, 'items' => [
                'type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'text' => ['type' => 'string', 'maxLength' => 2000],
                ], 'required' => ['title', 'text'], 'additionalProperties' => false,
            ]]);
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->propose($arguments, ['patch', 'inspirationCards'], static function () use ($arguments): array {
            $patch = $arguments['patch'] ?? null;
            $cards = $arguments['inspirationCards'] ?? [];
            if (! is_array($patch) || ($patch !== [] && array_is_list($patch)) || ! is_array($cards)) {
                throw new InvalidArgumentException('Invalid canvas patch or inspiration cards.');
            }

            return ['patch' => $patch, 'cards' => $cards, 'summary' => 'Canvas patch applied'];
        });
    }
}
