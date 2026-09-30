<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\UI\Template;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Whiteboards\Permissions\WhiteboardsPermissions;
use Leantime\Domain\Whiteboards\Services\WhiteboardAccess;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use Leantime\Domain\Whiteboards\Support\WhiteboardConflictException;
use Symfony\Component\HttpFoundation\Response;

final class WhiteboardController
{
    public function __construct(
        private readonly Whiteboards $boards,
        private readonly WhiteboardAccess $access,
        private readonly Projects $projects,
        private readonly Template $template,
    ) {}

    public function index(int $projectId): Response
    {
        $boards = $this->boards->listBoards($projectId, $this->actorId());
        $project = $this->projects->getProject($projectId);
        if (! is_array($project)) {
            throw new NotFoundException;
        }
        $this->template->requireComponents([]);
        $this->template->assign('project', $project);
        $this->template->assign('boards', $boards);
        $this->template->assign('canCreateBoard', $this->can($projectId, WhiteboardsPermissions::CREATE));

        return $this->template->display('whiteboards.index');
    }

    public function show(int $boardId): Response
    {
        $board = $this->boards->board($boardId, $this->actorId());
        $this->template->requireComponents([]);
        $this->template->assign('board', $board);
        $this->template->assign('revisions', $this->boards->revisions($boardId, $this->actorId()));
        $this->template->assign('canEditBoard', $this->can((int) $board['project_id'], WhiteboardsPermissions::EDIT));

        return $this->template->display('whiteboards.board');
    }

    public function create(Request $request, int $projectId): Response
    {
        return $this->json(fn (): array => $this->boards->createBoard(
            $projectId, $this->actorId(), (string) $request->input('title', '')
        ), 201);
    }

    public function scene(int $boardId): Response
    {
        return $this->json(fn (): array => $this->boards->board($boardId, $this->actorId()));
    }

    public function saveScene(Request $request, int $boardId): Response
    {
        return $this->json(function () use ($request, $boardId): array {
            $scene = $request->input('scene');
            if (! is_array($scene)) {
                throw new InvalidArgumentException('Provide a Whiteboard scene.');
            }

            return $this->boards->saveScene(
                $boardId, $this->actorId(), $this->revisionInput($request, 'expectedRevision'), $scene
            );
        });
    }

    public function revisions(int $boardId): Response
    {
        return $this->json(fn (): array => ['revisions' => $this->boards->revisions($boardId, $this->actorId())]);
    }

    public function revision(int $boardId, int $revision): Response
    {
        return $this->json(fn (): array => $this->boards->revision($boardId, $this->actorId(), $revision));
    }

    public function restoreRevision(Request $request, int $boardId, int $revision): Response
    {
        return $this->json(fn (): array => $this->boards->restoreRevision(
            $boardId, $this->actorId(), $this->revisionInput($request, 'expectedRevision'), $revision
        ));
    }

    private function actorId(): int
    {
        $id = (int) session('userdata.id');
        if ($id <= 0) {
            throw new AuthorizationException;
        }

        return $id;
    }

    private function can(int $projectId, string $permission): bool
    {
        try {
            $this->access->authorize($projectId, $this->actorId(), $permission);

            return true;
        } catch (AuthorizationException|NotFoundException) {
            return false;
        }
    }

    private function revisionInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException('Provide the current Whiteboard revision.');
        }

        return (int) $value;
    }

    /** @param callable():array<string,mixed> $operation */
    private function json(callable $operation, int $status = 200): Response
    {
        try {
            return response()->json($operation(), $status);
        } catch (NotFoundException) {
            return response()->json(['error' => 'Whiteboard not found.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['error' => 'You cannot access this Whiteboard.'], 403);
        } catch (WhiteboardConflictException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
