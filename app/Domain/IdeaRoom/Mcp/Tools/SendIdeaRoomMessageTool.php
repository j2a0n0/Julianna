<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Services\WorkspaceChat;

final class SendIdeaRoomMessageTool extends IdeaRoomMutationTool
{
    public function __construct(private readonly WorkspaceChat $chat) {}

    public function name(): string
    {
        return 'sendIdeaRoomMessage';
    }

    public function description(): string
    {
        return 'Send a message through the configured Idea Room provider and tool loop. Workspace writes stop for user confirmation.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('roomId')->description('Accessible Idea Room ID.')->required()
            ->integer('expectedVersion')->description('Current canvas version; stale versions fail without starting a turn.')->required()
            ->raw('content', ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000])->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->safely(function () use ($arguments): ToolResult {
            $this->checkArguments($arguments, ['roomId', 'expectedVersion', 'content']);
            $roomId = $this->positiveId($arguments, 'roomId');
            $version = $this->version($arguments);
            $content = $this->stringValue($arguments, 'content', 10000);
            $this->chat->startTurn($roomId, $content, $version);
            $this->chat->runTurn($roomId);
            $state = $this->chat->state($roomId);
            $assistant = null;
            foreach (array_reverse($state['messages']) as $message) {
                if ($message['role'] === 'assistant') {
                    $assistant = ['id' => $message['id'], 'content' => $message['content']];
                    break;
                }
            }
            $activity = [];
            foreach (array_slice($state['events'], -30) as $event) {
                if (! str_starts_with((string) $event['event'], 'tool.')) {
                    continue;
                }
                $tool = $event['data']['tool'] ?? [];
                $activity[] = [
                    'event' => $event['event'],
                    'toolId' => $tool['id'] ?? null,
                    'toolName' => $tool['name'] ?? null,
                ];
            }
            $pending = array_map(static fn (array $action): array => [
                'id' => $action['id'],
                'toolName' => $action['tool_name'],
                'status' => $action['status'],
                'destructive' => (bool) $action['destructive'],
                'expiresAt' => $action['expires_at'],
            ], $state['pendingActions']);
            $generation = (string) ($state['generation']['status'] ?? 'unknown');

            return StructuredIdeaRoomToolResult::success('Idea Room turn '.$generation.'; '.count($pending).' action(s) await user confirmation.', [
                'roomId' => $roomId,
                'assistant' => $assistant,
                'plan' => $state['room']['plan'],
                'graphVersion' => (int) ($state['room']['graph_version'] ?? 0),
                'planVersion' => (int) ($state['room']['plan_version'] ?? 0),
                'generation' => $generation,
                'toolActivity' => $activity,
                'pendingConfirmations' => $pending,
                'url' => '/idea-room/'.$roomId,
            ]);
        });
    }
}
