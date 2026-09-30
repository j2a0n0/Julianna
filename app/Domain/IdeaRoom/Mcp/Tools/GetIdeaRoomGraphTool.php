<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;

#[IsReadOnly]
final class GetIdeaRoomGraphTool extends AbstractIdeaRoomReadTool
{
    public function __construct(private readonly IdeaGraph $graph) {}

    public function name(): string
    {
        return 'getIdeaRoomGraph';
    }

    public function description(): string
    {
        return 'Get the permission-filtered Idea Room canvas, graph version, and IDs of recoverable deleted nodes.';
    }

    protected function read(array $arguments): array
    {
        $roomId = self::roomId($arguments);
        $graph = $this->graph->graph($roomId)['graph'];
        $recoverableNodes = array_map(static fn (array $node): array => [
            'id' => (int) $node['id'],
            'type' => (string) $node['type'],
            'title' => (string) $node['title'],
        ], $this->graph->recoverableNodes($roomId));

        return [
            'summary' => 'Canvas version '.$graph['version'].' has '.count($graph['nodes']).' nodes, '.count($graph['links']).' links, and '.count($recoverableNodes).' recoverable nodes.',
            'data' => ['roomId' => $roomId, 'graph' => $graph, 'recoverableNodes' => $recoverableNodes],
        ];
    }
}
