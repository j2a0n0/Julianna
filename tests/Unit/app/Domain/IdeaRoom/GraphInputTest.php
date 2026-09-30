<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom;

use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\Support\GraphInput;
use PHPUnit\Framework\TestCase;

final class GraphInputTest extends TestCase
{
    private function node(): array
    {
        return ['clientId' => 'local-1', 'type' => 'idea', 'title' => 'A possibility', 'content' => 'Details', 'x' => 12, 'y' => -4, 'metadata' => []];
    }

    public function test_valid_node_and_typed_link(): void
    {
        self::assertSame('idea', GraphInput::node($this->node())['type']);
        self::assertSame('supports', GraphInput::link(['clientId' => 'link-1', 'sourceId' => 'local-1', 'targetId' => 2, 'type' => 'supports'])['type']);
    }

    public function test_rejects_oversized_content_and_invalid_reference(): void
    {
        $node = $this->node();
        $node['content'] = str_repeat('x', 10001);
        try {
            GraphInput::node($node);
            self::fail('Oversized content was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $node = $this->node();
        $node['metadata'] = ['reference' => ['type' => 'project', 'id' => '99']];
        $this->expectException(InvalidArgumentException::class);
        GraphInput::node($node);
    }

    public function test_rejects_invalid_relationship_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GraphInput::link(['clientId' => 'link-1', 'sourceId' => 1, 'targetId' => 2, 'type' => 'controls']);
    }

    public function test_rejects_unsafe_source_url(): void
    {
        $node = $this->node();
        $node['type'] = 'source';
        $node['metadata'] = ['url' => 'javascript:alert(1)'];
        $this->expectException(InvalidArgumentException::class);
        GraphInput::node($node);
    }
}
