<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\WorkspaceChat;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** The command center's project-scoped agent state and explicit draft review. */
final class ProjectAgentController
{
    public function __construct(
        private readonly ProjectAgent $agent,
        private readonly RoomRepository $rooms,
        private readonly WorkspaceChat $chat,
    ) {}

    public function show(int $projectId): Response
    {
        return $this->json(fn (): array => [
            'settings' => $this->agent->settings($projectId),
            'activities' => $this->agent->activities($projectId),
            'runs' => $this->agent->runs($projectId),
            'drafts' => array_map(self::presentAction(...), $this->rooms->projectActionsByStatus($projectId, 'draft')),
            'staleItems' => array_map(self::presentAction(...), $this->rooms->projectActionsByStatus($projectId, 'stale')),
        ]);
    }

    public function configure(Request $request, int $projectId): Response
    {
        return $this->json(function () use ($request, $projectId): array {
            $enabled = $request->input('enabled');
            $paused = $request->input('paused', false);
            if (! is_bool($enabled) || ! is_bool($paused)) {
                throw new InvalidArgumentException('Provide enabled and paused as booleans.');
            }

            return ['settings' => $this->agent->configure($projectId, $enabled, $paused)];
        });
    }

    public function undo(int $projectId, int $activityId): Response
    {
        return $this->json(fn (): array => ['activity' => $this->agent->undo($projectId, $activityId)]);
    }

    public function publishDraft(int $roomId, int $actionId): Response
    {
        return $this->json(fn (): array => $this->chat->publishDraft($roomId, $actionId));
    }

    public function discardDraft(int $roomId, int $actionId): Response
    {
        return $this->json(fn (): array => $this->chat->discardDraft($roomId, $actionId));
    }

    /** @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private static function presentAction(array $action): array
    {
        return $action + [
            'roomId' => (int) ($action['room_id'] ?? 0),
            'toolName' => (string) ($action['tool_name'] ?? ''),
            'createdAt' => (string) ($action['created_at'] ?? ''),
        ];
    }

    /** @param callable(): array<string, mixed> $action */
    private function json(callable $action): Response
    {
        try {
            return response()->json($action());
        } catch (NotFoundException) {
            return response()->json(['error' => 'The item could not be found.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['error' => 'You are not allowed to perform this action.'], 403);
        } catch (InvalidArgumentException $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        } catch (RuntimeException) {
            return response()->json(['error' => 'The action could not be completed. Please retry.'], 500);
        }
    }
}
