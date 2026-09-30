<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\AI;

use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\AI\CanvasPatch;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\IdeaRoomPrompt;
use PHPUnit\Framework\TestCase;

final class CanvasPatchTest extends TestCase
{
    public function test_reviewable_patch_preserves_graph_edits_and_inspiration(): void
    {
        $patch = CanvasPatch::fromToolArguments([
            'patch' => ['nodes' => [[
                'clientId' => 'new-idea', 'type' => 'idea', 'title' => 'Try a pilot',
                'content' => '', 'x' => 40, 'y' => 80,
            ]]],
            'inspirationCards' => [['title' => 'Perspective', 'text' => 'Ask a client']],
        ]);

        self::assertSame('new-idea', $patch->patch['nodes'][0]['clientId']);
        self::assertCount(1, $patch->inspirationCards);
        self::assertSame('proposeCanvasPatch', CanvasPatch::toolDefinition()->name);
    }

    public function test_tool_schema_describes_the_node_format_required_by_the_graph(): void
    {
        $definition = CanvasPatch::toolDefinition();
        $patch = $definition->parameters['properties']['patch'];
        $node = $patch['properties']['nodes']['items'];
        $link = $patch['properties']['links']['items'];

        self::assertSame(20, $patch['properties']['nodes']['maxItems']);
        self::assertSame(40, $patch['properties']['links']['maxItems']);
        self::assertSame(['type', 'title', 'x', 'y'], $node['required']);
        self::assertSame(false, $node['additionalProperties']);
        self::assertArrayHasKey('clientId', $node['properties']);
        self::assertArrayHasKey('content', $node['properties']);
        self::assertArrayNotHasKey('text', $node['properties']);
        self::assertSame(['idea', 'question', 'insight', 'source', 'decision', 'next_step'], $node['properties']['type']['enum']);
        self::assertSame(['related_to', 'supports', 'contradicts', 'depends_on', 'leads_to'], $link['properties']['type']['enum']);
        self::assertStringContainsString('clientId', IdeaRoomPrompt::toolMessages(new ChatRequest(
            [['role' => 'user', 'content' => 'Suggest a canvas idea']], tools: [$definition],
        ))[0]['content']);
        self::assertStringContainsString('Do not mistake one of those nodes for a pre-existing duplicate',
            IdeaRoomPrompt::toolMessages(new ChatRequest(
                [['role' => 'user', 'content' => 'Suggest a canvas idea']], tools: [$definition],
            ))[0]['content']);
        self::assertStringContainsString('Use current_graph directly to answer read-only questions about the current canvas',
            IdeaRoomPrompt::toolMessages(new ChatRequest(
                [['role' => 'user', 'content' => 'Count current nodes']], tools: [$definition],
            ))[0]['content']);
    }

    public function test_malformed_or_empty_patch_is_rejected(): void
    {
        foreach ([
            [],
            ['patch' => []],
            ['patch' => ['unknown' => true]],
            ['patch' => ['nodes' => 'not a list']],
            ['patch' => ['nodes' => array_fill(0, 21, [])]],
            ['patch' => ['plan' => 'not an object']],
            ['patch' => [], 'inspirationCards' => 'not a list'],
        ] as $arguments) {
            try {
                CanvasPatch::fromToolArguments($arguments);
                self::fail('A malformed proposal was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_graph_citations_and_mode_are_supplied_as_data_to_provider(): void
    {
        $request = new ChatRequest(
            [['role' => 'user', 'content' => 'What next?']],
            ['outcome' => 'Pilot'],
            'Client project',
            'fr-CH',
            [CanvasPatch::toolDefinition()],
            ['version' => 2, 'nodes' => [['id' => 4, 'type' => 'source']], 'links' => []],
            [['id' => 7, 'url' => 'https://example.org/source']],
            'execute',
        );

        $messages = IdeaRoomPrompt::toolMessages($request);
        self::assertSame('system', $messages[1]['role']);
        self::assertStringContainsString('"mode":"execute"', $messages[1]['content']);
        self::assertStringContainsString('example.org', $messages[1]['content']);
        self::assertStringContainsString('"version":2', $messages[1]['content']);
    }
}
