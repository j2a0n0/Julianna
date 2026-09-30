<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Controllers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\UI\Template;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\TemporaryAiConfiguration;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Search\SearchException;
use Leantime\Domain\IdeaRoom\Search\SearchProviderFactory;
use Leantime\Domain\IdeaRoom\Search\SearchRequest;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\IdeaRoom\Services\WorkspaceChat;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class IdeaRoomController
{
    public function __construct(
        private readonly IdeaRoom $rooms,
        private readonly Template $template,
        private readonly WorkspaceChat $chat,
        private readonly IdeaGraph $graph,
        private readonly RoomRepository $repository,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function index(): Response
    {
        $this->template->assign('rooms', $this->rooms->myRooms());
        $this->template->assign('projects', $this->rooms->accessibleProjects());
        $this->template->assign('providerConfigured', $this->rooms->providerConfigured());
        $this->template->assign('temporaryAiEnabled', TemporaryAiConfiguration::enabled());
        $this->template->assign('temporaryAi', TemporaryAiConfiguration::details());
        $this->template->assign('canCreateProject', $this->rooms->canCreateProject());

        return $this->template->display('idearoom.index');
    }

    public function show(int $id): Response
    {
        $room = $this->rooms->room($id);
        $this->template->assign('room', $room);
        $projectId = (int) ($room['project_id'] ?? 0);
        $this->template->assign('agentProjectId', $projectId);
        $this->template->assign('agentUrl', $projectId > 0 ? '/idea-room/projects/'.$projectId.'/agent' : '');
        $this->template->assign('messages', $this->rooms->messages($id));
        $this->template->assign('plan', $room['plan']);
        $this->template->assign('providerConfigured', $this->rooms->providerConfigured());
        $this->template->assign('temporaryAiEnabled', TemporaryAiConfiguration::enabled());
        $this->template->assign('temporaryAi', TemporaryAiConfiguration::details());
        $this->template->assign('canEdit', $this->rooms->canEdit($room));
        $this->template->assign('canApprove', $this->rooms->canApprove($room));
        $this->template->assign('canArchive', $this->rooms->canArchive($room));
        $this->template->assign('canChat', $this->rooms->canChat($room));
        $this->template->assign('projects', $this->rooms->accessibleProjects());
        $this->template->assign('canCreateProject', $this->rooms->canCreateProject());
        $this->template->assign('pendingActions', $this->chat->pendingActions($id));
        $this->template->assign('canChangeContext', $this->rooms->canEdit($room)
            && $this->chat->pendingActions($id) === []
            && ! in_array($this->chat->state($id)['generation']['status'] ?? null, ['running', 'awaiting_confirmation'], true));
        $graphEnabled = $this->graphEnabled();
        $this->template->assign('graphEnabled', $graphEnabled);
        $this->template->assign('canEditGraph', $graphEnabled && $this->rooms->canEdit($room));
        $this->template->assign('searchConfigured', SearchProviderFactory::fromEnvironment() !== null);
        if ($graphEnabled) {
            $this->template->assign('graph', $this->graph->graph($id)['graph']);
            $this->template->assign('sources', $this->graph->sources($id));
            $this->template->assign('history', $this->graph->history($id));
            $this->template->assign('recoverableNodes', $this->graph->recoverableNodes($id));
            $this->template->assign('linkableRooms', $this->graph->linkableRooms($id));
        }

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

    public function saveTemporaryAi(Request $request): Response
    {
        return $this->json(function () use ($request): array {
            return ['configuration' => TemporaryAiConfiguration::save(
                $request,
                (string) $request->input('provider', ''),
                (string) $request->input('model', ''),
                (string) $request->input('apiKey', ''),
            )];
        });
    }

    public function clearTemporaryAi(Request $request): Response
    {
        return $this->json(function () use ($request): array {
            TemporaryAiConfiguration::clear($request);

            return ['configured' => $this->rooms->providerConfigured()];
        });
    }

    public function state(int $id): Response
    {
        return $this->json(fn (): array => $this->chat->state($id));
    }

    public function graphState(int $id): Response
    {
        return $this->json(function () use ($id): array {
            $this->requireGraph();

            return [...$this->graph->graph($id), 'sources' => $this->graph->sources($id),
                'history' => $this->graph->history($id), 'linkableRooms' => $this->graph->linkableRooms($id),
                'recoverableNodes' => $this->graph->recoverableNodes($id)];
        });
    }

    public function historyState(int $id): Response
    {
        return $this->json(function () use ($id): array {
            $this->requireGraph();

            return ['history' => $this->graph->history($id)];
        });
    }

    public function restoreHistory(Request $request, int $id, int $entryId): Response
    {
        return $this->json(function () use ($request, $id, $entryId): array {
            $this->requireGraph();

            return $this->graph->restoreHistory(
                $id,
                $entryId,
                $this->expectedVersion($request),
                $this->expectedPlanVersion($request),
            );
        });
    }

    public function restoreNode(Request $request, int $id, int $nodeId): Response
    {
        return $this->json(function () use ($request, $id, $nodeId): array {
            $this->requireGraph();

            return $this->graph->restoreNodes($id, [$nodeId], $this->expectedVersion($request));
        });
    }

    public function saveGraph(Request $request, int $id): Response
    {
        return $this->json(function () use ($request, $id): array {
            $this->requireGraph();
            $version = $this->expectedVersion($request);
            $nodes = $request->input('nodes');
            $links = $request->input('links');
            if (! is_array($nodes) || ! array_is_list($nodes)
                || ! is_array($links) || ! array_is_list($links)) {
                throw new InvalidArgumentException('Provide a valid graph snapshot.');
            }

            return $this->graph->save($id, $version, $nodes, $links);
        });
    }

    public function research(Request $request, int $id): Response
    {
        $provider = SearchProviderFactory::fromEnvironment();
        if ($provider === null) {
            return response()->json(['error' => 'Research is not configured. Ask an administrator to set up a search provider.'], 503);
        }

        return $this->json(function () use ($request, $id, $provider): array {
            $this->requireGraph();
            $room = $this->rooms->room($id);
            if (! $this->rooms->canChat($room)) {
                throw new AuthorizationException;
            }
            $query = new SearchRequest((string) $request->input('query', ''));
            $key = 'idea-research:'.hash('sha256', (string) session('userdata.id').'|'.(string) $request->ip());
            if ($this->rateLimiter->tooManyAttempts($key, 10)) {
                throw new TooManyRequestsHttpException(3600, 'Too many searches. Please try again later.');
            }
            $this->rateLimiter->hit($key, 3600);
            $this->repository->addEvent($id, 'research.started', ['query' => $query->query]);
            try {
                $response = $provider->search($query);
                $sources = $this->graph->storeSources($id, $response->results);
                $this->repository->addEvent($id, 'research.completed', ['query' => $query->query, 'sources' => $sources]);

                return ['sources' => $sources];
            } catch (SearchException) {
                $this->repository->addEvent($id, 'research.failed', []);
                throw new ProviderException('Research is unavailable. Please try again.');
            }
        });
    }

    public function keepSource(Request $request, int $id, int $sourceId): Response
    {
        return $this->json(function () use ($request, $id, $sourceId): array {
            $this->requireGraph();

            return $this->graph->keepSource($id, $sourceId, $this->expectedVersion($request));
        });
    }

    public function mode(Request $request, int $id): Response
    {
        return $this->json(function () use ($request, $id): array {
            $this->requireGraph();

            return $this->graph->mode($id, (string) $request->input('mode', ''));
        });
    }

    private function graphEnabled(): bool
    {
        $raw = $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] ?? getenv('JULIANNA_IDEA_GRAPH_ENABLED');

        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function requireGraph(): void
    {
        if (! $this->graphEnabled()) {
            throw new InvalidArgumentException('The Idea Room canvas is not enabled.');
        }
    }

    private function expectedVersion(Request $request): int
    {
        $version = filter_var($request->input('expectedVersion'), FILTER_VALIDATE_INT);
        if (! is_int($version) || $version < 0) {
            throw new InvalidArgumentException('Provide a valid graph version.');
        }

        return $version;
    }

    private function expectedPlanVersion(Request $request): int
    {
        $version = filter_var($request->input('expectedPlanVersion'), FILTER_VALIDATE_INT);
        if (! is_int($version) || $version < 0) {
            throw new InvalidArgumentException('Provide a valid plan version.');
        }

        return $version;
    }

    public function context(Request $request, int $id): Response
    {
        return $this->json(function () use ($request, $id): array {
            $value = $request->input('projectId');
            $projectId = $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_INT);
            if ($projectId === false || ($projectId !== null && $projectId < 1)) {
                throw new InvalidArgumentException('Choose a valid project.');
            }

            return $this->rooms->updateContext($id, $projectId);
        });
    }

    public function turn(Request $request, int $id): Response
    {
        try {
            $this->chat->startTurn($id, (string) $request->input('content', ''));
        } catch (NotFoundException) {
            return response()->json(['error' => 'The idea room could not be found.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['error' => 'You are not allowed to perform this action.'], 403);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->streamTurn($id);
    }

    public function retry(int $id): Response
    {
        try {
            $this->chat->retryTurn($id);
        } catch (NotFoundException) {
            return response()->json(['error' => 'The idea room could not be found.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['error' => 'You are not allowed to perform this action.'], 403);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->streamTurn($id);
    }

    public function events(Request $request, int $id): Response
    {
        $this->rooms->room($id);
        $after = max(0, (int) $request->query('after', $request->header('Last-Event-ID', 0)));

        return new StreamedResponse(function () use ($id, $after): void {
            $cursor = $after;
            $until = time() + 15;
            do {
                foreach ($this->chat->events($id, $cursor) as $event) {
                    self::writeEvent($event);
                    $cursor = (int) $event['id'];
                }
                if (connection_aborted()) {
                    return;
                }
                usleep(250000);
            } while (time() < $until);
        }, 200, $this->streamHeaders());
    }

    public function confirm(Request $request, int $id, int $actionId): Response
    {
        if (str_contains((string) $request->header('Accept', ''), 'text/event-stream')) {
            return new StreamedResponse(function () use ($id, $actionId): void {
                ignore_user_abort(true);
                try {
                    $this->chat->resolveAction($id, $actionId, true, static function (array $event): void {
                        self::writeEvent($event);
                    });
                } catch (\Throwable) {
                    self::writeEvent(['id' => 0, 'event' => 'message.error',
                        'data' => ['error' => 'The action could not be completed. Please retry.']]);
                }
            }, 200, $this->streamHeaders());
        }

        return $this->json(fn (): array => $this->chat->resolveAction($id, $actionId, true));
    }

    public function reject(int $id, int $actionId): Response
    {
        return $this->json(fn (): array => $this->chat->resolveAction($id, $actionId, false));
    }

    public function cancel(int $id): Response
    {
        return $this->json(fn (): array => $this->chat->cancel($id));
    }

    /** @return array<string, string> */
    private function streamHeaders(): array
    {
        return ['Content-Type' => 'text/event-stream; charset=UTF-8', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no'];
    }

    private function streamTurn(int $id): Response
    {
        return new StreamedResponse(function () use ($id): void {
            ignore_user_abort(true);
            $this->chat->runTurn($id, static function (array $event): void {
                self::writeEvent($event);
            });
        }, 200, $this->streamHeaders());
    }

    /** @param array<string, mixed> $event */
    private static function writeEvent(array $event): void
    {
        echo 'id: '.(int) $event['id']."\n";
        echo 'event: '.(string) $event['event']."\n";
        echo 'data: '.json_encode($event['data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    public function savePlan(Request $request, int $id): Response
    {
        return $this->json(function () use ($request, $id): array {
            $plan = $request->input('plan');
            if (! is_array($plan)) {
                throw new InvalidArgumentException('Provide a structured plan.');
            }

            return $this->graph->replacePlan($id, $plan);
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
        } catch (GraphConflictException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        } catch (TooManyRequestsHttpException $exception) {
            return response()->json(['error' => $exception->getMessage()], 429);
        } catch (ProviderException $exception) {
            return response()->json(['error' => $exception->getMessage()], 502);
        } catch (RuntimeException $exception) {
            // Never log transcript contents or provider credentials, including in exception chains.
            return response()->json(['error' => 'The action could not be completed. Please retry.'], 500);
        }
    }
}
