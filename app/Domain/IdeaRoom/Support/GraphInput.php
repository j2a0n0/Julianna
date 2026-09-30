<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Support;

use InvalidArgumentException;
use JsonException;

final class GraphInput
{
    public const MAX_NODES = 200;

    public const MAX_LINKS = 400;

    public const NODE_TYPES = ['idea', 'question', 'insight', 'source', 'decision', 'next_step'];

    public const LINK_TYPES = ['related_to', 'supports', 'contradicts', 'depends_on', 'leads_to'];

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public static function node(array $node): array
    {
        if (array_diff(array_keys($node), ['id', 'clientId', 'type', 'title', 'content', 'x', 'y', 'metadata', 'authorUserId', 'createdAt', 'updatedAt']) !== []) {
            throw new InvalidArgumentException('Unknown canvas node field.');
        }
        $type = $node['type'] ?? null;
        $title = $node['title'] ?? null;
        $content = $node['content'] ?? '';
        $x = $node['x'] ?? null;
        $y = $node['y'] ?? null;
        $metadata = $node['metadata'] ?? [];
        try {
            $metadataLength = is_array($metadata) ? strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) : PHP_INT_MAX;
        } catch (JsonException) {
            throw new InvalidArgumentException('The canvas contains invalid node metadata.');
        }
        if (! in_array($type, self::NODE_TYPES, true)
            || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 255
            || ! is_string($content) || mb_strlen($content) > 10000
            || ! is_numeric($x) || ! is_numeric($y) || ! is_finite((float) $x) || ! is_finite((float) $y)
            || abs((float) $x) > 100000 || abs((float) $y) > 100000
            || ! is_array($metadata) || array_is_list($metadata) && $metadata !== []
            || $metadataLength > 4000) {
            throw new InvalidArgumentException('The canvas contains an invalid node.');
        }
        if (isset($metadata['reference'])) {
            $reference = $metadata['reference'];
            if (! is_array($reference)
                || ! in_array($reference['type'] ?? null, ['room', 'project'], true)
                || ! is_int($reference['id'] ?? null) || $reference['id'] < 1
                || array_diff(array_keys($reference), ['type', 'id']) !== []) {
                throw new InvalidArgumentException('The canvas contains an invalid reference.');
            }
        }
        if (isset($metadata['sourceId']) && (! is_int($metadata['sourceId']) || $metadata['sourceId'] < 1 || $type !== 'source')) {
            throw new InvalidArgumentException('The canvas contains an invalid source reference.');
        }
        if (isset($metadata['url']) && (! is_string($metadata['url'])
            || strlen($metadata['url']) > 2048
            || ! in_array(strtolower((string) parse_url($metadata['url'], PHP_URL_SCHEME)), ['http', 'https'], true)
            || ! is_string(parse_url($metadata['url'], PHP_URL_HOST)))) {
            throw new InvalidArgumentException('The canvas contains an invalid source URL.');
        }
        self::identity($node);

        return [
            'id' => $node['id'] ?? null,
            'clientId' => $node['clientId'] ?? null,
            'type' => $type,
            'title' => trim($title),
            'content' => $content,
            'x' => (float) $x,
            'y' => (float) $y,
            'metadata' => $metadata,
        ];
    }

    /** @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public static function link(array $link): array
    {
        if (array_diff(array_keys($link), ['id', 'clientId', 'sourceId', 'targetId', 'type']) !== []) {
            throw new InvalidArgumentException('Unknown canvas link field.');
        }
        self::identity($link);
        if (! in_array($link['type'] ?? null, self::LINK_TYPES, true)
            || ! self::endpoint($link['sourceId'] ?? null)
            || ! self::endpoint($link['targetId'] ?? null)
            || $link['sourceId'] === $link['targetId']) {
            throw new InvalidArgumentException('The canvas contains an invalid link.');
        }

        return [
            'id' => $link['id'] ?? null,
            'clientId' => $link['clientId'] ?? null,
            'sourceId' => $link['sourceId'],
            'targetId' => $link['targetId'],
            'type' => $link['type'],
        ];
    }

    /** @param array<string, mixed> $item */
    private static function identity(array $item): void
    {
        if (isset($item['id']) && (! is_int($item['id']) || $item['id'] < 1)) {
            throw new InvalidArgumentException('The canvas contains an invalid ID.');
        }
        if (isset($item['clientId']) && (! is_string($item['clientId']) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $item['clientId']))) {
            throw new InvalidArgumentException('The canvas contains an invalid client ID.');
        }
        if (! isset($item['id']) && ! isset($item['clientId'])) {
            throw new InvalidArgumentException('New canvas elements need a client ID.');
        }
    }

    private static function endpoint(mixed $endpoint): bool
    {
        return (is_int($endpoint) && $endpoint > 0)
            || (is_string($endpoint) && (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $endpoint));
    }
}
