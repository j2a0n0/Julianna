<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;

#[IsReadOnly]
final class ListIdeaRoomCanvasHistoryTool extends AbstractIdeaRoomReadTool
{
    public function __construct(private readonly IdeaGraph $graph) {}

    public function name(): string
    {
        return 'listIdeaRoomCanvasHistory';
    }

    public function description(): string
    {
        return 'List permission-checked Idea Room canvas and plan revisions, newest first. Returns metadata only, not snapshot contents.';
    }

    protected function read(array $arguments): array
    {
        $roomId = self::roomId($arguments);
        $history = $this->graph->history($roomId);

        return [
            'summary' => count($history).' canvas history revision'.(count($history) === 1 ? '' : 's').' available.',
            'data' => ['roomId' => $roomId, 'history' => $history],
        ];
    }
}
