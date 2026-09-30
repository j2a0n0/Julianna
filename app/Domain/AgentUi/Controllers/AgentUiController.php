<?php

declare(strict_types=1);

namespace Leantime\Domain\AgentUi\Controllers;

use Illuminate\Http\Request;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\UI\Template;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Agent\Services\AiConfiguration;
use Leantime\Domain\IdeaRoom\Repositories\GraphRepository;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Symfony\Component\HttpFoundation\Response;

/** Read-only pages and server-derived context for the in-app agent. */
final class AgentUiController
{
    public function __construct(
        private readonly Template $template,
        private readonly PermissionService $permissions,
        private readonly IdeaRoom $ideaRooms,
        private readonly IdeaGraph $ideaGraph,
        private readonly RoomRepository $rooms,
        private readonly GraphRepository $graphs,
    ) {}

    public function show(): Response
    {
        return $this->renderAgent(null);
    }

    public function project(int $projectId): Response
    {
        return $this->renderAgent($projectId);
    }

    public function context(Request $request): Response
    {
        $projects = $this->accessibleProjects();
        $currentId = (int) session('currentProject');
        if (! $this->containsProject($projects, $currentId)) {
            $currentId = 0;
        }

        // The referring page is supplied by the browser's same-origin navigation,
        // not by a model-supplied project ID. It is display context only; the
        // runtime independently authorizes the selected project on every tool call.
        $referrer = (string) $request->headers->get('referer', '');
        $pagePath = '/';
        if ($referrer !== '' && parse_url($referrer, PHP_URL_SCHEME) === parse_url(BASE_URL, PHP_URL_SCHEME)
            && parse_url($referrer, PHP_URL_HOST) === parse_url(BASE_URL, PHP_URL_HOST)
            && parse_url($referrer, PHP_URL_PORT) === parse_url(BASE_URL, PHP_URL_PORT)) {
            $pagePath = (string) (parse_url($referrer, PHP_URL_PATH) ?: '/');
        }
        if (preg_match('#^/(?:agent|whiteboards)/projects/([1-9][0-9]*)(?:/|$)#', $pagePath, $match)
            && $this->containsProject($projects, (int) $match[1])) {
            $currentId = (int) $match[1];
        }

        return response()->json([
            'pagePath' => $pagePath,
            'projectId' => $currentId > 0 ? $currentId : null,
            'projects' => $projects,
        ]);
    }

    public function archive(Request $request): Response
    {
        $projects = $this->accessibleProjects();
        $userId = (int) session('userdata.id');
        if ($userId <= 0) {
            throw new AuthorizationException;
        }
        $cursor = filter_var($request->query('beforeId', 0), FILTER_VALIDATE_INT);
        if ($cursor === false || $cursor < 0) {
            $cursor = 0;
        }
        $candidates = $this->rooms->listAccessibleCandidatesPage(
            $userId,
            array_column($projects, 'id'),
            Auth::userIsAtLeast(Roles::$admin, forceGlobalRoleCheck: true),
            $cursor,
            51,
        );
        $hasMore = count($candidates) > 50;
        $candidates = array_slice($candidates, 0, 50);
        $visible = [];
        foreach ($candidates as $candidate) {
            try {
                $visible[] = $this->ideaRooms->room((int) $candidate['id']);
            } catch (AuthorizationException|NotFoundException) {
                // Access may have changed since the room was created.
            }
        }

        $this->template->assign('rooms', $visible);
        $this->template->assign('nextCursor', $hasMore && $candidates !== [] ? (int) end($candidates)['id'] : null);

        return $this->template->display('agentui.archive');
    }

    public function archivedRoom(int $roomId): Response
    {
        $room = $this->ideaRooms->room($roomId);
        $this->template->assign('room', $room);
        $this->template->assign('messages', $this->ideaRooms->messages($roomId));
        $this->template->assign('graph', $this->ideaGraph->graph($roomId)['graph']);
        $this->template->assign('history', $this->ideaGraph->history($roomId));
        $this->template->assign('sources', array_values(array_filter(
            $this->ideaGraph->sources($roomId),
            static fn (array $source): bool => is_string($source['url'] ?? null)
                && in_array(strtolower((string) parse_url($source['url'], PHP_URL_SCHEME)), ['http', 'https'], true),
        )));
        $this->template->assign('unexecutedActions', array_merge(
            $this->rooms->pendingActions($roomId),
            $this->rooms->actionsByStatus($roomId, 'stale'),
        ));

        return $this->template->display('agentui.archived-room');
    }

    public function archivedRevision(int $roomId, int $entryId): Response
    {
        $room = $this->ideaRooms->room($roomId);
        $entry = $this->graphs->historyEntry($roomId, $entryId) ?? throw new NotFoundException;
        $this->template->assign('room', $room);
        $this->template->assign('revision', $this->ideaGraph->filterSnapshotForCurrentUser($roomId, $entry));

        return $this->template->display('agentui.archived-revision');
    }

    private function renderAgent(?int $requestedProjectId): Response
    {
        $projects = $this->accessibleProjects();
        $selected = $requestedProjectId ?? (int) session('currentProject');
        if ($requestedProjectId !== null && ! $this->containsProject($projects, $selected)) {
            throw new NotFoundException;
        }
        if (! $this->containsProject($projects, $selected)) {
            $selected = 0;
        }
        $this->template->assign('projects', $projects);
        $this->template->assign('selectedProjectId', $selected);
        $this->template->assign('canConfigureAgent', $selected > 0
            && $this->permissions->currentUserCan(ProjectsPermissions::EDIT, $selected));
        $this->template->assign('providerConfigured', app()->make(AiConfiguration::class)->provider() !== null);

        return $this->template->display('agentui.command-center');
    }

    /** @return list<array{id:int,name:string}> */
    private function accessibleProjects(): array
    {
        return array_values(array_map(
            static fn (array $project): array => ['id' => (int) $project['id'], 'name' => (string) ($project['name'] ?? '')],
            $this->ideaRooms->accessibleProjects(),
        ));
    }

    /** @param list<array{id:int,name:string}> $projects */
    private function containsProject(array $projects, int $id): bool
    {
        foreach ($projects as $project) {
            if ($id > 0 && $project['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
