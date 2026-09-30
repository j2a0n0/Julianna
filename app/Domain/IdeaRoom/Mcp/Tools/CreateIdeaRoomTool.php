<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

final class CreateIdeaRoomTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaRoom $rooms) {}

    public function name(): string
    {
        return 'createIdeaRoom';
    }

    public function description(): string
    {
        return 'Start a private Idea Room from a 1–5000 character idea, optionally linked to an accessible project. No workspace artifacts are created.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('idea', ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000])->required()
            ->raw('projectId', ['type' => ['integer', 'null'], 'minimum' => 1])
            ->description('Existing accessible project ID, or null for a new-project idea.');
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['idea'], ['projectId']);
            $idea = $this->stringValue($arguments, 'idea', 5000);
            $projectId = $arguments['projectId'] ?? null;
            if ($projectId !== null && (! is_int($projectId) || $projectId < 1)) {
                throw new InvalidArgumentException('Provide a valid projectId.');
            }
            $room = $this->rooms->create($idea, $projectId);

            return StructuredIdeaRoomToolResult::success('Idea Room #'.$room['id'].' created.', [
                'room' => $room,
                'url' => '/idea-room/'.$room['id'],
            ]);
        });
    }
}
