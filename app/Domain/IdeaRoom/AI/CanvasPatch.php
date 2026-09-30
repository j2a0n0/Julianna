<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\Support\GraphInput;

/** A provider-authored room change, never permission to mutate workspace records. */
final class CanvasPatch
{
    /**
     * @param  array<string, mixed>  $patch
     * @param  list<array<string, mixed>>  $inspirationCards
     */
    private function __construct(
        public readonly array $patch,
        public readonly array $inspirationCards,
    ) {}

    /** @param array<string, mixed> $arguments */
    public static function fromToolArguments(array $arguments): self
    {
        if (array_diff(array_keys($arguments), ['patch', 'inspirationCards']) !== []
            || ! is_array($arguments['patch'] ?? null)
            || ($arguments['patch'] !== [] && array_is_list($arguments['patch']))
            || ! is_array($arguments['inspirationCards'] ?? [])
            || ! array_is_list($arguments['inspirationCards'] ?? [])) {
            throw new InvalidArgumentException('The assistant proposed an invalid canvas change.');
        }

        $patch = $arguments['patch'];
        if (array_diff(array_keys($patch), ['nodes', 'links', 'removeNodeIds', 'removeLinkIds', 'plan']) !== []) {
            throw new InvalidArgumentException('The assistant proposed an invalid canvas change.');
        }
        foreach (['nodes' => 20, 'links' => 40, 'removeNodeIds' => 20, 'removeLinkIds' => 40] as $key => $limit) {
            if (! is_array($patch[$key] ?? []) || ! array_is_list($patch[$key] ?? [])
                || count($patch[$key] ?? []) > $limit) {
                throw new InvalidArgumentException('The assistant proposed an invalid canvas change.');
            }
        }
        if (! is_array($patch['plan'] ?? []) || count($arguments['inspirationCards'] ?? []) > 10) {
            throw new InvalidArgumentException('The assistant proposed an invalid canvas change.');
        }
        if ($patch === [] && ($arguments['inspirationCards'] ?? []) === []) {
            throw new InvalidArgumentException('The assistant proposed an empty canvas change.');
        }

        return new self($patch, $arguments['inspirationCards'] ?? []);
    }

    public static function toolDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'proposeCanvasPatch',
            'Apply canvas nodes, relationships, inspiration cards, or plan edits immediately. Changes are reversible through Idea Room history and never create workspace records. New nodes need clientId, type, title, x and y; use content for body text.',
            [
                'type' => 'object',
                'properties' => [
                    'patch' => [
                        'type' => 'object',
                        'properties' => [
                            'nodes' => ['type' => 'array', 'maxItems' => 20, 'items' => [
                                'type' => 'object',
                                'description' => 'For a new node, set clientId to a unique short ASCII identifier and omit id. For an existing node, set its numeric id. Always include type, title, x and y. Use content, not text, for body text.',
                                'properties' => [
                                    'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Existing canvas node ID only.'],
                                    'clientId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'description' => 'Required for a new node; use this in links to that node.'],
                                    'type' => ['type' => 'string', 'enum' => GraphInput::NODE_TYPES],
                                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                                    'content' => ['type' => 'string', 'maxLength' => 10000],
                                    'x' => ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000],
                                    'y' => ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000],
                                    'metadata' => ['type' => 'object'],
                                ],
                                'required' => ['type', 'title', 'x', 'y'],
                                'additionalProperties' => false,
                            ]],
                            'links' => ['type' => 'array', 'maxItems' => 40, 'items' => [
                                'type' => 'object',
                                'description' => 'A new link needs its own unique clientId. Use numeric IDs for existing nodes, or clientId strings for new nodes in this patch.',
                                'properties' => [
                                    'id' => ['type' => 'integer', 'minimum' => 1],
                                    'clientId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'description' => 'Required for a new link.'],
                                    'sourceId' => ['description' => 'Positive integer existing node ID or clientId string of a new node.'],
                                    'targetId' => ['description' => 'Positive integer existing node ID or clientId string of a new node.'],
                                    'type' => ['type' => 'string', 'enum' => GraphInput::LINK_TYPES],
                                ],
                                'required' => ['sourceId', 'targetId', 'type'],
                                'additionalProperties' => false,
                            ]],
                            'removeNodeIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'removeLinkIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'plan' => ['type' => 'object'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'inspirationCards' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'text'],
                        'additionalProperties' => false,
                    ]],
                ],
                'required' => ['patch'],
                'additionalProperties' => false,
            ],
            readOnly: false,
        );
    }
}
