<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Controllers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Agent\Services\Agent;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class AgentApiController
{
    public function __construct(
        private readonly Agent $agent,
        private readonly ProjectAgent $projects,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function listConversations(Request $request): Response
    {
        return $this->json(function () use ($request): array {
            $projectId = $this->optionalProjectId($request->query('projectId'));

            return [
                'conversations' => $this->agent->conversations($this->actorId(), $projectId),
                'providerConfigured' => $this->agent->providerConfigured(),
            ];
        });
    }

    public function createConversation(Request $request): Response
    {
        return $this->json(fn (): array => [
            'conversation' => $this->agent->createConversation(
                $this->actorId(),
                $this->optionalProjectId($request->input('projectId')),
                $this->sameOriginPagePath($request),
            ),
        ], 201);
    }

    public function showConversation(int $conversationId): Response
    {
        return $this->json(fn (): array => $this->agent->conversation($conversationId, $this->actorId()));
    }

    public function turn(Request $request, int $conversationId): Response
    {
        return $this->json(function () use ($request, $conversationId): array {
            $actorId = $this->actorId();
            $clientRequestId = (string) $request->input('clientRequestId', '');
            if (! $this->agent->requestAlreadyStored($conversationId, $actorId, $clientRequestId)) {
                $key = 'julianna-agent-turn:user:'.$actorId;
                $limit = $this->hourlyTurnLimit();
                if ($this->rateLimiter->tooManyAttempts($key, $limit)) {
                    throw new TooManyRequestsHttpException($this->rateLimiter->availableIn($key),
                        'Too many AI requests. Please try again later.');
                }
                $this->rateLimiter->hit($key, 3600);
            }

            return $this->agent->turn($conversationId, $actorId,
                (string) $request->input('message', ''), $clientRequestId);
        });
    }

    public function project(int $projectId): Response
    {
        return $this->json(fn (): array => [
            'settings' => $this->projects->settings($projectId),
            'activities' => $this->projects->activities($projectId),
            'runs' => $this->projects->runs($projectId),
            'drafts' => $this->agent->drafts($projectId, $this->actorId()),
            'questions' => $this->agent->questions($projectId, $this->actorId()),
            'providerConfigured' => $this->agent->providerConfigured(),
        ]);
    }

    public function questions(Request $request): Response
    {
        return $this->json(fn (): array => [
            'questions' => $this->agent->questions($this->optionalProjectId($request->query('projectId')), $this->actorId()),
        ]);
    }

    public function configureProject(Request $request, int $projectId): Response
    {
        return $this->json(function () use ($request, $projectId): array {
            $enabled = $request->input('enabled');
            $paused = $request->input('paused', false);
            if (! is_bool($enabled) || ! is_bool($paused)) {
                throw new InvalidArgumentException('Provide enabled and paused as booleans.');
            }

            return ['settings' => $this->projects->configure($projectId, $enabled, $paused)];
        });
    }

    public function undo(Request $request, int $activityId): Response
    {
        return $this->json(function () use ($request, $activityId): array {
            $projectId = $this->optionalProjectId($request->input('projectId'));
            if ($projectId === null) {
                throw new InvalidArgumentException('Select the activity project.');
            }

            return ['activity' => $this->projects->undo($projectId, $activityId)];
        });
    }

    public function publishDraft(int $draftId): Response
    {
        return $this->json(fn (): array => $this->agent->publishDraft($draftId, $this->actorId()));
    }

    public function discardDraft(int $draftId): Response
    {
        return $this->json(fn (): array => ['draft' => $this->agent->discardDraft($draftId, $this->actorId())]);
    }

    private function actorId(): int
    {
        $actorId = (int) session('userdata.id');
        if ($actorId < 1) {
            throw new AuthorizationException;
        }

        return $actorId;
    }

    private function optionalProjectId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('Choose a valid project.');
        }

        return $id;
    }

    private function hourlyTurnLimit(): int
    {
        $configured = $_ENV['JULIANNA_AI_REQUESTS_PER_HOUR'] ?? getenv('JULIANNA_AI_REQUESTS_PER_HOUR');
        $value = filter_var($configured, FILTER_VALIDATE_INT);

        return $value === false || $value < 1 ? 20 : min(100, $value);
    }

    /** Page context is an informational same-origin browser hint, never authority. */
    private function sameOriginPagePath(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');
        if (! is_string($referrer) || $referrer === '') {
            return null;
        }
        $parts = parse_url($referrer);
        if ($parts === false || ! isset($parts['host'], $parts['path'])
            || strcasecmp($parts['host'], $request->getHost()) !== 0
            || strcasecmp((string) ($parts['scheme'] ?? ''), $request->getScheme()) !== 0
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== $request->getPort())
            || strlen($parts['path']) > 512) {
            return null;
        }

        return $parts['path'];
    }

    /** @param callable():array<string,mixed> $callback */
    private function json(callable $callback, int $success = 200): Response
    {
        try {
            return response()->json($callback(), $success);
        } catch (NotFoundException) {
            return response()->json(['error' => 'The item could not be found.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['error' => 'You are not allowed to perform this action.'], 403);
        } catch (InvalidArgumentException $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        } catch (ProviderException) {
            return response()->json(['error' => 'The AI provider could not complete this request. Please try again.'], 503);
        } catch (TooManyRequestsHttpException $error) {
            return response()->json(['error' => 'Too many AI requests. Please try again later.'], 429,
                ['Retry-After' => (string) $error->getHeaders()['Retry-After']]);
        } catch (Throwable) {
            return response()->json(['error' => 'The request could not be completed.'], 500);
        }
    }
}
