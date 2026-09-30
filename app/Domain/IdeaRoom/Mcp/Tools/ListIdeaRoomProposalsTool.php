<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;

#[IsReadOnly]
final class ListIdeaRoomProposalsTool extends AbstractIdeaRoomReadTool
{
    public function __construct(private readonly IdeaGraph $graph) {}

    public function name(): string
    {
        return 'listIdeaRoomProposals';
    }

    public function description(): string
    {
        return 'List reviewable canvas proposals and their pending, accepted, or rejected status.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return parent::schema($schema)
            ->integer('beforeId')->description('Optional proposal ID cursor; return older proposals.')
            ->integer('limit')->description('Page size from 1 to 50; default 20.');
    }

    protected function allowedArguments(): array
    {
        return ['roomId', 'beforeId', 'limit'];
    }

    protected function read(array $arguments): array
    {
        $roomId = self::roomId($arguments);
        $limit = self::pageSize($arguments);
        $page = $this->graph->proposalsPage($roomId, self::cursor($arguments, 'beforeId'), $limit);

        return [
            'summary' => count($page['proposals']).' canvas proposal'.(count($page['proposals']) === 1 ? '' : 's').' in this page.',
            'data' => ['roomId' => $roomId, 'proposals' => $page['proposals'], 'nextCursor' => $page['nextCursor'], 'limit' => $limit],
        ];
    }
}
