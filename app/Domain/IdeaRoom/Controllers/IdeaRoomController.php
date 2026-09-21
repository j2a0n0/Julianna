<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\UI\Template;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class IdeaRoomController
{
    public function __construct(
        private readonly IdeaRoom $rooms,
        private readonly Template $template,
    ) {}

    public function index(): Response
    {
        $this->template->assign('rooms', $this->rooms->myRooms());
        $this->template->assign('projects', $this->rooms->accessibleProjects());
        $this->template->assign('providerConfigured', $this->rooms->providerConfigured());
        $this->template->assign('canCreateProject', $this->rooms->canCreateProject());

        return $this->template->display('idearoom.index');
    }

    public function show(int $id): Response
    {
        $room = $this->rooms->room($id);
        $this->template->assign('room', $room);
        $this->template->assign('messages', $this->rooms->messages($id));
        $this->template->assign('plan', $room['plan']);
        $this->template->assign('providerConfigured', $this->rooms->providerConfigured());
        $this->template->assign('canEdit', $this->rooms->canEdit($room));
        $this->template->assign('canApprove', $this->rooms->canApprove($room));
        $this->template->assign('canArchive', $this->rooms->canArchive($room));

        return $this->template->display('idearoom.room');
    }

    public function create(Request $request): Response
    {
        return $this->json(function () use ($request): array {
            $project = $request->input('projectId');
            $projectId = $project === null || $project === '' ? null : filter_var($project, FILTER_VALIDATE_INT);
            if ($projectId === false || ($projectId !== null && $projectId < 1)) {
                throw new InvalidArgumentException('Choose a valid project.');
            }

            return [
                'room' => $this->rooms->create((string) $request->input('idea', ''), $projectId),
            ];
        }, 201);
    }

    public function send(Request $request, int $id): Response
    {
        return $this->json(fn (): array => $this->rooms->send($id, (string) $request->input('content', '')));
    }

    public function savePlan(Request $request, int $id): Response
    {
        return $this->json(function () use ($request, $id): array {
            $plan = $request->input('plan');
            if (! is_array($plan)) {
                throw new InvalidArgumentException('Provide a structured plan.');
            }

            return $this->rooms->savePlan($id, $plan);
        });
    }

    public function approve(int $id): Response
    {
        return $this->json(fn (): array => $this->rooms->approve($id));
    }

    public function archive(int $id): Response
    {
        return $this->json(fn (): array => $this->rooms->archive($id));
    }

    /** @param callable(): array<string, mixed> $action */
    private function json(callable $action, int $successCode = 200): Response
    {
        try {
            return response()->json($action(), $successCode);
        } catch (NotFoundException $exception) {
            return response()->json(['error' => 'The idea room could not be found.'], 404);
        } catch (AuthorizationException $exception) {
            return response()->json(['error' => 'You are not allowed to perform this action.'], 403);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (ProviderException $exception) {
            return response()->json(['error' => $exception->getMessage()], 502);
        } catch (RuntimeException $exception) {
            // Never log transcript contents or provider credentials, including in exception chains.
            return response()->json(['error' => 'The action could not be completed. Please retry.'], 500);
        }
    }
}
