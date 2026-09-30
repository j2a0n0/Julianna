<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;

final class UpdateIdeaRoomContextTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly IdeaRoom $rooms) {}

    public function name(): string
    {
        return 'updateIdeaRoomContext';
    }

    public function description(): string
    {
        return 'Change an editable room to an accessible existing project, or to new-project context. This does not create workspace records.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->required()
            ->integer('expectedVersion')->description('Current canvas version; stale versions fail.')->required()
            ->raw('projectId', ['type' => ['integer', 'null'], 'minimum' => 1])
            ->description('Accessible existing project ID, or null to create a project only after user approval.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'expectedVersion', 'projectId']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $version = $this->version($arguments);
            $projectId = $arguments['projectId'];
            if ($projectId !== null && (! is_int($projectId) || $projectId < 1)) {
                throw new InvalidArgumentException('Provide a valid projectId.');
            }
            $result = $this->rooms->updateContext($roomId, $projectId, $version);

            return StructuredIdeaRoomToolResult::success('Idea Room context updated.', [
                'room' => $result['room'],
                'url' => '/idea-room/'.$roomId,
            ]);
        });
    }
}
