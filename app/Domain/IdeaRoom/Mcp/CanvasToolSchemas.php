<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

final class CanvasToolSchemas
{
    public const NODE_TYPES = ['idea', 'question', 'insight', 'source', 'decision', 'next_step'];

    public const LINK_TYPES = ['related_to', 'supports', 'contradicts', 'depends_on', 'leads_to'];

    /** @return array<string, mixed> */
    public static function metadata(): array
    {
        return [
            'type' => 'object',
            'description' => 'At most 4000 JSON bytes. reference must be {type: room|project, id: positive integer}; sourceId must belong to this room.',
            'properties' => [
                'reference' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['room', 'project']],
                    'id' => ['type' => 'integer', 'minimum' => 1],
                ], 'required' => ['type', 'id'], 'additionalProperties' => false],
                'sourceId' => ['type' => 'integer', 'minimum' => 1],
                'url' => ['type' => 'string', 'maxLength' => 2048, 'format' => 'uri'],
                'domain' => ['type' => 'string', 'maxLength' => 255],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function node(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'clientId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
            'type' => ['type' => 'string', 'enum' => self::NODE_TYPES],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'content' => ['type' => 'string', 'maxLength' => 10000],
            'x' => ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000],
            'y' => ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000],
            'metadata' => self::metadata(),
        ], 'required' => ['type', 'title', 'x', 'y'], 'additionalProperties' => false];
    }

    /** @return array<string, mixed> */
    public static function link(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'clientId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
            'sourceId' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$']]],
            'targetId' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$']]],
            'type' => ['type' => 'string', 'enum' => self::LINK_TYPES],
        ], 'required' => ['sourceId', 'targetId', 'type'], 'additionalProperties' => false];
    }

    /** @return array<string, mixed> */
    public static function patch(): array
    {
        return ['type' => 'object', 'properties' => [
            'nodes' => ['type' => 'array', 'maxItems' => 200, 'items' => self::node()],
            'links' => ['type' => 'array', 'maxItems' => 400, 'items' => self::link()],
            'removeNodeIds' => self::idList(200),
            'removeLinkIds' => self::idList(400),
            'restoreNodeIds' => self::idList(200),
            'plan' => ['type' => 'object', 'description' => 'Partial plan update; valid fields: projectName, outcome, milestones, tasks, assumptions, openQuestions.'],
            'mode' => ['type' => 'string', 'enum' => ['explore', 'execute']],
        ], 'additionalProperties' => false];
    }

    /** @return array<string, mixed> */
    private static function idList(int $max): array
    {
        return ['type' => 'array', 'maxItems' => $max, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1]];
    }
}
