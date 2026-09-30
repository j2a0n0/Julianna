<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Services;

use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Domain\Agent\AI\AgentPrompt;
use Leantime\Domain\Agent\AI\ReplyLocale;
use Leantime\Domain\Agent\Repositories\AgentRepository;
use Leantime\Domain\IdeaRoom\AI\AiProvider;
use Leantime\Domain\IdeaRoom\AI\AssistantTurn;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\ToolDefinition;
use Leantime\Domain\Mcp\Services\ToolCatalog;
use Leantime\Domain\Mcp\Services\ToolDispatcher;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use RuntimeException;
use Throwable;

/** In-app and queued agent use the same tool dispatcher as external MCP clients. */
final class Agent
{
    private const MAX_TOOL_ROUNDS = 6;
    private const MAX_CALLS_PER_ROUND = 4;
    private const MAX_CONTEXT_BYTES = 180000;
    private const STALE_TURN_SECONDS = 1200;
    private const UNCERTAIN_ACTIVITY_OUTCOME = 'The action started; its outcome is not yet confirmed. Check the workspace before requesting it again.';

    public function __construct(
        private readonly AgentRepository $store,
        private readonly ToolCatalog $catalog,
        private readonly ToolDispatcher $dispatcher,
        private readonly ProjectAgent $projectAgent,
        private readonly PermissionService $permissions,
        private readonly ?AiProvider $provider = null,
    ) {}

    public function providerConfigured(): bool
    {
        return $this->provider !== null || app()->make(AiConfiguration::class)->provider() !== null;
    }

    public function requestAlreadyStored(int $conversationId, int $actorId, string $clientRequestId): bool
    {
        $conversation = $this->store->conversation($conversationId, $actorId);
        $this->assertProjectView($conversation['project_id'] === null ? null : (int) $conversation['project_id'], $actorId);
        if (! preg_match('/^[A-Za-z0-9_-]{12,128}$/', $clientRequestId)) {
            return false;
        }

        return $this->store->turnByClientKey($conversationId, hash('sha256', $clientRequestId)) !== null;
    }

    /** @return list<array<string,mixed>> */
    public function conversations(int $actorId, ?int $projectId = null): array
    {
        $visible = [];
        foreach ($this->store->conversations($actorId, $projectId) as $row) {
            try {
                $this->assertProjectView($row['project_id'] === null ? null : (int) $row['project_id'], $actorId);
                $visible[] = $this->presentConversation($row);
            } catch (AuthorizationException) {
                // A custom role may have lost VIEW while retaining membership.
            }
        }

        return $visible;
    }

    /** @return array<string,mixed> */
    public function createConversation(int $actorId, ?int $projectId, ?string $pagePath = null, string $source = 'interactive'): array
    {
        $this->assertProjectView($projectId, $actorId, $source);
        if ($pagePath !== null && (! str_starts_with($pagePath, '/') || str_starts_with($pagePath, '//')
            || strlen($pagePath) > 512 || preg_match('/[\x00-\x1F\x7F]/', $pagePath))) {
            $pagePath = null;
        }

        return $this->presentConversation($this->store->createConversation($actorId, $projectId, $pagePath));
    }

    /** @return array{conversation:array<string,mixed>,turns:list<array<string,mixed>>} */
    public function conversation(int $conversationId, int $actorId, string $source = 'interactive'): array
    {
        $conversation = $this->store->conversation($conversationId, $actorId);
        $this->assertProjectView($conversation['project_id'] === null ? null : (int) $conversation['project_id'], $actorId, $source);

        return [
            'conversation' => $this->presentConversation($conversation),
            'turns' => array_map($this->presentTurn(...), $this->store->turns($conversationId)),
        ];
    }

    /**
     * A client request ID is required so a repeated HTTP submission returns the
     * existing transcript rather than sending another model request.
     *
     * @return array{conversation:array<string,mixed>,turns:list<array<string,mixed>>}
     */
    public function turn(int $conversationId, int $actorId, string $message, string $clientRequestId, string $source = 'interactive', ?int $runId = null): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 10000
            || ! preg_match('/^[A-Za-z0-9_-]{12,128}$/', $clientRequestId)
            || ! in_array($source, ['interactive', 'background'], true)) {
            throw new InvalidArgumentException('Write a message in 1–10000 characters and provide a request ID.');
        }
        $provider = $this->provider ?? app()->make(AiConfiguration::class)->provider();
        if ($provider === null) {
            throw new InvalidArgumentException('A server-side AI provider is not configured.');
        }
        $replyLocale = $source === 'background'
            ? $this->locale($actorId, $source)
            : ReplyLocale::forMessage($message, $this->locale($actorId, $source));
        $clientKey = hash('sha256', $clientRequestId);
        $conversation = $this->store->transaction(function () use ($conversationId, $actorId, $message, $clientKey, $source, $replyLocale): array {
            $row = $this->store->conversation($conversationId, $actorId, lock: true);
            $this->assertProjectView($row['project_id'] === null ? null : (int) $row['project_id'], $actorId, $source);
            if ($row['state'] === 'running' && strtotime((string) $row['updated_at'].' UTC') <= time() - self::STALE_TURN_SECONDS) {
                // A dead worker/request has no authority to resume old tool
                // calls. Mark its transcript interrupted, then accept a new
                // explicit user request after a conservative lease timeout.
                $this->store->addTurn($conversationId, 'assistant', $replyLocale === 'fr-CH'
                    ? 'La demande précédente a été interrompue. Vérifiez l’activité avant de continuer.'
                    : 'The previous request was interrupted. Check recent activity before continuing.',
                    ['interrupted' => true]);
                $this->store->setConversationState($conversationId, 'idle');
                $row = $this->store->conversation($conversationId, $actorId, lock: true);
            }
            if ($this->store->turnByClientKey($conversationId, $clientKey) !== null) {
                return ['row' => $row, 'replay' => true];
            }
            if ($row['state'] !== 'idle') {
                throw new InvalidArgumentException('This conversation already has a turn in progress.');
            }
            $this->store->addTurn($conversationId, 'user', $message, clientKey: $clientKey);
            $title = $row['title'] === 'New conversation' ? mb_substr($message, 0, 80) : null;
            $this->store->setConversationState($conversationId, 'running', $title);
            $this->store->resolveQuestions($conversationId);

            return ['row' => $row, 'replay' => false];
        });
        if ($conversation['replay']) {
            return $this->conversation($conversationId, $actorId, $source);
        }
        $projectId = $conversation['row']['project_id'] === null ? null : (int) $conversation['row']['project_id'];
        if ($source === 'background' && ($projectId === null || ! $this->projectAgent->isBackgroundRunnable($projectId, $actorId))) {
            $this->store->setConversationState($conversationId, 'idle');
            throw new AuthorizationException;
        }

        try {
            $attemptedWrites = [];
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $this->store->conversation($conversationId, $actorId); // recheck live owner/account/project access
                $this->assertProjectView($projectId, $actorId, $source);
                if ($source === 'background' && $runId !== null && $projectId !== null) {
                    $this->projectAgent->heartbeat($runId, $projectId, $actorId);
                }
                $definitions = $this->modelDefinitions($projectId, $actorId, $source);
                $taskToolAvailable = in_array('addTask', array_map(
                    static fn (ToolDefinition $tool): string => $tool->name, $definitions,
                ), true);
                $request = new ChatRequest(
                    messages: $this->providerMessages($conversationId, $taskToolAvailable),
                    projectContext: json_encode([
                        'selected_project_id' => $projectId,
                        'page_path' => $conversation['row']['page_path'],
                        'source' => $source,
                    ], JSON_THROW_ON_ERROR),
                    locale: $replyLocale,
                    tools: $definitions,
                    mode: 'execute',
                    systemPrompt: AgentPrompt::forTools($definitions, $replyLocale),
                );
                $reply = $provider->turn($request);
                if ($source === 'interactive' && $taskToolAvailable && $attemptedWrites === []
                    && $reply->toolCalls === [] && self::falseTaskToolClaim($reply->text)) {
                    // No effect has run in this request. Give the model one
                    // chance to correct a false capability refusal before it
                    // becomes durable conversation history.
                    $retry = new ChatRequest(
                        messages: $request->messages,
                        projectContext: $request->projectContext,
                        locale: $request->locale,
                        tools: $definitions,
                        mode: 'execute',
                        systemPrompt: AgentPrompt::forTools($definitions, $replyLocale)
                            ."\nYour previous draft falsely said task creation tools are unavailable. "
                            .'addTask and bulkAddTasks are supplied in this request. Use them for a clear task-creation request; '
                            .'otherwise ask only for substantive missing direction. Do not repeat the false tool claim.',
                    );
                    $reply = $provider->turn($retry);
                    if ($reply->toolCalls === [] && self::falseTaskToolClaim($reply->text)) {
                        $reply = new AssistantTurn($request->locale === 'fr-CH'
                            ? 'Je peux créer des tâches dans ce projet, mais cette demande n’a pas abouti. Aucune tâche n’a été créée cette fois-ci. Réessayez, et je vérifierai les actions proposées.'
                            : 'I can create tasks in this project, but this request did not complete. No tasks were created this time. Please try again and I will check the proposed actions.', [], 'completed');
                    }
                }
                if (count($reply->toolCalls) > self::MAX_CALLS_PER_ROUND) {
                    throw new ProviderException('The assistant requested too many actions.');
                }
                $metadata = ['tool_calls' => array_map(static fn ($call): array => $call->toArray(), $reply->toolCalls)];
                if ($reply->reasoningContent !== null) {
                    // Kimi's tool-turn protocol requires reasoning_content to
                    // be sent back on the next request. It stays server-side.
                    $metadata['reasoning_content'] = $reply->reasoningContent;
                }
                $this->store->addTurn($conversationId, 'assistant', $reply->text, $metadata);
                if ($reply->toolCalls === []) {
                    $this->recordQuestionIfNeeded($conversationId, $projectId, $reply->text);
                    $this->store->setConversationState($conversationId, 'idle');

                    return $this->conversation($conversationId, $actorId, $source);
                }
                foreach ($reply->toolCalls as $call) {
                    if ($source === 'background' && $runId !== null && $projectId !== null) {
                        $this->projectAgent->heartbeat($runId, $projectId, $actorId);
                    }
                    $result = $this->executeCall($conversationId, $actorId, $projectId, $call->id, $call->name,
                        $call->arguments, $source, $runId, $attemptedWrites);
                    $this->store->addTurn($conversationId, 'tool', json_encode($result, JSON_THROW_ON_ERROR), [
                        'tool_call_id' => $call->id,
                        'name' => $call->name,
                        'is_error' => ! $result['ok'],
                    ]);
                }
            }
            $this->store->addTurn($conversationId, 'assistant', $replyLocale === 'fr-CH'
                ? 'J’ai atteint la limite d’actions pour cette demande. Continuez dans un nouveau message.'
                : 'I reached the action limit for this request. Please continue in a new message.');
            $this->store->setConversationState($conversationId, 'idle');

            return $this->conversation($conversationId, $actorId, $source);
        } catch (ProviderException $error) {
            // Persist a terminal response so replaying the same HTTP request is
            // safe and intelligible, including when earlier tool calls did run.
            // A fresh user request is required to continue after checking activity.
            $this->store->addTurn($conversationId, 'assistant', self::providerFailureReply($error->getCode(), $replyLocale),
                ['interrupted' => true]);
            $this->store->setConversationState($conversationId, 'idle');

            return $this->conversation($conversationId, $actorId, $source);
        } catch (Throwable $error) {
            try {
                $this->store->transaction(function () use ($conversationId, $actorId, $source, $replyLocale, $error): void {
                    if (! $error instanceof AuthorizationException) {
                        // A failed request must never look successfully completed
                        // when the browser safely replays its client request ID.
                        $this->store->addTurn($conversationId, 'assistant', $replyLocale === 'fr-CH'
                            ? 'La demande s’est arrêtée avant la fin. Vérifiez l’activité récente avant de continuer dans un nouveau message.'
                            : 'The request stopped before completion. Check recent activity before continuing in a new message.',
                            ['interrupted' => true]);
                    }
                    $this->store->setConversationState($conversationId, 'idle');
                });
            } catch (Throwable) {
                // Keep the original failure; a database outage may make a
                // terminal transcript impossible to persist at this moment.
            }
            throw $error;
        }
    }

    private static function providerFailureReply(int $status, string $locale): string
    {
        $detail = $locale === 'fr-CH'
            ? match ($status) {
                401 => 'Le fournisseur d’IA a refusé la clé API enregistrée. Remplacez-la dans les paramètres de l’agent, puis réessayez.',
                403 => 'Le fournisseur d’IA a refusé l’accès. Vérifiez la clé et les droits du compte dans les paramètres de l’agent.',
                400, 404 => 'Le fournisseur d’IA a refusé la demande ou le modèle choisi. Vérifiez le modèle dans les paramètres de l’agent.',
                402 => 'Le fournisseur d’IA demande une configuration de facturation. Vérifiez le compte du fournisseur.',
                429 => 'Le fournisseur d’IA a atteint sa limite de requêtes. Réessayez plus tard.',
                default => 'Le fournisseur d’IA s’est arrêté avant la fin.',
            }
            : match ($status) {
                401 => 'The AI provider rejected the saved API key. Replace it in Agent settings, then try again.',
                403 => 'The AI provider denied access. Check the key and account permissions in Agent settings.',
                400, 404 => 'The AI provider rejected the request or selected model. Check the model in Agent settings.',
                402 => 'The AI provider requires billing setup. Check the provider account.',
                429 => 'The AI provider rate limit was reached. Try again later.',
                default => 'The AI provider stopped before I could finish.',
            };

        return $detail.($locale === 'fr-CH'
            ? ' Je n’ai répété aucune action. Vérifiez l’activité récente avant de continuer dans un nouveau message.'
            : ' I did not repeat any action. Check recent activity before continuing in a new message.');
    }

    /** @return array<string,mixed> */
    public function backgroundReview(int $projectId, int $actorId, int $runId, string $trigger): array
    {
        if (! $this->projectAgent->isBackgroundRunnable($projectId, $actorId)) {
            throw new AuthorizationException;
        }
        if (! $this->providerConfigured()) {
            throw new InvalidArgumentException('Background AI provider is not configured.');
        }
        $conversation = $this->createConversation($actorId, $projectId, source: 'background');
        $request = $trigger === 'daily'
            ? 'Review this project for routine upkeep. Check accessible status and tasks using tools. Do only clear, useful, non-duplicative actions; otherwise ask one focused question.'
            : 'A project item changed. Review the selected project for a clear, useful follow-up. Do not invent work or duplicate an existing action; ask a focused question if direction is missing.';

        return $this->turn((int) $conversation['id'], $actorId, $request, 'run-'.$runId.'-project-'.$projectId, 'background', $runId);
    }

    /** @return list<array<string,mixed>> */
    public function drafts(int $projectId, int $actorId): array
    {
        $this->store->assertScope($actorId, $projectId);
        $this->assertProjectView($projectId, $actorId);
        if (! $this->permissions->currentUserCan(ProjectsPermissions::EDIT, $projectId)) {
            return [];
        }

        return array_map($this->presentDraft(...), $this->store->drafts($projectId));
    }

    /** @return list<array<string,mixed>> */
    public function questions(?int $projectId, int $actorId): array
    {
        $this->store->assertScope($actorId, $projectId);
        $this->assertProjectView($projectId, $actorId);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'projectId' => $row['project_id'] === null ? null : (int) $row['project_id'],
            'question' => (string) $row['question'],
            'createdAt' => (string) $row['created_at'],
        ], $this->store->questions($projectId, $actorId));
    }

    /** @return array<string,mixed> */
    public function publishDraft(int $draftId, int $actorId): array
    {
        $draft = $this->store->draft($draftId);
        $projectId = (int) $draft['project_id'];
        $this->store->assertScope($actorId, $projectId);
        $this->assertProjectView($projectId, $actorId);
        $this->permissions->authorize(ProjectsPermissions::EDIT, $projectId);
        if ($draft['status'] !== 'draft') {
            throw new InvalidArgumentException('This draft is no longer available for review.');
        }
        $arguments = json_decode((string) $draft['arguments_json'], true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($arguments)) {
            throw new InvalidArgumentException('Invalid draft.');
        }
        $this->dispatcher->authorize((string) $draft['tool_name'], $arguments, $actorId, $projectId, 'publish');
        if (! $this->store->setDraftStatus($draftId, 'draft', 'publishing')) {
            throw new InvalidArgumentException('This draft has already been reviewed.');
        }
        // A publishing draft is deliberately not retried after an uncertain crash.
        $activity = $this->projectAgent->recordActivity($projectId, $actorId, [
            'action' => (string) $draft['tool_name'],
            'rationale' => 'The PM reviewed a communication draft for publication.',
            'outcome' => self::UNCERTAIN_ACTIVITY_OUTCOME,
            'status' => 'pending',
            'idempotencyKey' => 'draft:'.$draftId,
        ]);
        if ($activity['status'] !== 'pending') {
            throw new RuntimeException('This publication has an earlier activity and was not repeated.');
        }
        $result = $this->dispatcher->dispatch((string) $draft['tool_name'], $arguments, $actorId, $projectId, 'publish');
        if ($result['ok']) {
            if (! $this->store->setDraftStatus($draftId, 'publishing', 'published')) {
                throw new RuntimeException('Draft publication could not be confirmed.');
            }
        } else {
            // An explicit tool-level failure is known, unlike a crash after
            // dispatch. Keep it visible for PM inspection but do not auto-retry.
            if (! $this->store->setDraftStatus($draftId, 'publishing', 'failed')) {
                throw new RuntimeException('Draft failure could not be confirmed.');
            }
        }
        $this->projectAgent->finishActivity($projectId, (int) $activity['id'], $actorId,
            $result['ok'] ? 'Communication published after PM review.' : 'Communication could not be published.',
            $result['ok'] ? 'completed' : 'failed');

        return ['draft' => $this->presentDraft($this->store->draft($draftId)), 'result' => $result];
    }

    /** @return array<string,mixed> */
    public function discardDraft(int $draftId, int $actorId): array
    {
        $draft = $this->store->draft($draftId);
        $this->store->assertScope($actorId, (int) $draft['project_id']);
        $this->assertProjectView((int) $draft['project_id'], $actorId);
        $this->permissions->authorize(ProjectsPermissions::EDIT, (int) $draft['project_id']);
        $discarded = $this->store->setDraftStatus($draftId, 'draft', 'discarded')
            || $this->store->setDraftStatus($draftId, 'failed', 'discarded');
        if (! $discarded) {
            throw new InvalidArgumentException('This draft has already been reviewed.');
        }

        return $this->presentDraft($this->store->draft($draftId));
    }

    /** @param array<string,mixed> $arguments @return array{ok:bool,text:string,data?:array} */
    private function executeCall(
        int $conversationId,
        int $actorId,
        ?int $projectId,
        string $callId,
        string $name,
        array $arguments,
        string $source,
        ?int $runId,
        array &$attemptedWrites,
    ): array {
        $definition = $this->catalog->definition($name);
        $effect = (string) $definition['effect'];
        $hash = hash('sha256', json_encode([$name, $arguments], JSON_THROW_ON_ERROR));
        if (! $this->store->createReceipt($conversationId, $actorId, $projectId, $callId, $name, $hash, $effect)) {
            $previous = $this->store->receipt($conversationId, $callId);
            if ($previous !== null && $previous['arguments_hash'] === $hash
                && in_array($previous['status'], ['completed', 'failed'], true)
                && $previous['result_json'] !== null) {
                return json_decode((string) $previous['result_json'], true, 64, JSON_THROW_ON_ERROR);
            }

            return ['ok' => false, 'text' => 'This action has an uncertain earlier attempt and was not repeated.'];
        }
        $backgroundKey = null;
        $activityId = null;
        try {
            if ($effect !== 'read') {
                $actionKey = hash('sha256', json_encode([$name, $this->canonicalArguments($arguments)], JSON_THROW_ON_ERROR));
                if (isset($attemptedWrites[$actionKey])) {
                    $result = ['ok' => false, 'text' => 'This action was already attempted in this request and was not repeated.'];
                    $this->store->finishReceipt($conversationId, $callId, $result);

                    return $result;
                }
                // A new model call ID cannot turn a failed or uncertain effect
                // into an automatic retry within the same user request.
                $attemptedWrites[$actionKey] = true;
            }
            if ($effect !== 'read' && ($projectId === null || ($source === 'background'
                && ! $this->projectAgent->isBackgroundRunnable($projectId, $actorId)))) {
                throw new AuthorizationException('Select a project before making changes.');
            }
            if ($effect !== 'read') {
                // Reject invalid scope, credentials and permissions before
                // recording a potentially executed operation.
                $this->dispatcher->authorize($name, $arguments, $actorId, $projectId, $source);
            }
            if ($source === 'background' && $effect !== 'read' && $projectId !== null) {
                $backgroundKey = hash('sha256', json_encode([
                    $projectId, $name, $this->canonicalArguments($arguments),
                ], JSON_THROW_ON_ERROR));
                $claim = $this->store->claimBackgroundAction($projectId, $actorId, $name, $backgroundKey);
                if (! $claim['created']) {
                    $result = ['ok' => $claim['status'] === 'completed', 'text' => $claim['status'] === 'completed'
                        ? 'This project action was already handled by an earlier review and was not repeated.'
                        : 'An earlier attempt at this project action is uncertain or failed; it was not repeated.'];
                    $this->store->finishReceipt($conversationId, $callId, $result);

                    return $result;
                }
            }
            if ($effect !== 'read' && $projectId !== null) {
                $activity = $this->projectAgent->recordActivity($projectId, $actorId, [
                    'action' => $name,
                    // Activity is project-shared. Do not expose model text or
                    // tool arguments in this pre-dispatch uncertainty record.
                    'rationale' => $source === 'background'
                        ? 'Routine project review selected this permitted action.'
                        : 'Requested in the agent conversation.',
                    'outcome' => self::UNCERTAIN_ACTIVITY_OUTCOME,
                    'status' => 'pending',
                    'runId' => $runId,
                    'idempotencyKey' => 'agent-call:'.$conversationId.':'.$callId,
                ]);
                if ($activity['status'] !== 'pending') {
                    throw new RuntimeException('The action already has an activity and was not repeated.');
                }
                $activityId = (int) $activity['id'];
            }
            if ($effect === 'communication') {
                $this->dispatcher->authorize($name, $arguments, $actorId, $projectId, $source);
                $draft = $this->store->createDraft($conversationId, $projectId, $actorId, $name, $arguments,
                    'Communication drafted by the assistant for PM review.');
                $result = ['ok' => true, 'text' => 'Communication saved as draft #'.$draft['id'].'. It has not been published.',
                    'data' => ['draftId' => (int) $draft['id']]];
            } else {
                $result = ProjectAgent::whileAgentAction(fn (): array => $this->dispatcher->dispatch(
                    $name, $arguments, $actorId, $projectId, $source
                ));
            }
            if ($activityId !== null && $projectId !== null) {
                $recovery = null;
                if (($definition['recovery'] ?? null) === 'revision' && ($result['ok'] ?? false)
                    && isset($result['data']['boardId'], $result['data']['revision'], $arguments['expectedRevision'])) {
                    $boardId = (int) $result['data']['boardId'];
                    $newRevision = (int) $result['data']['revision'];
                    $priorRevision = (int) $arguments['expectedRevision'];
                    if ($boardId > 0 && $newRevision > $priorRevision && $priorRevision >= 0) {
                        $recovery = [
                            'kind' => 'whiteboard_revision',
                            'boardId' => $boardId,
                            'expectedRevision' => $newRevision,
                            'revision' => $priorRevision,
                        ];
                    }
                }
                $this->projectAgent->finishActivity($projectId, $activityId, $actorId,
                    $effect === 'communication'
                        ? 'Communication saved as a draft for PM review.'
                        : $this->activityOutcome($name, $result),
                    $effect === 'communication' ? 'draft' : ($result['ok'] ? 'completed' : 'failed'),
                    $recovery);
            }
            if ($backgroundKey !== null) {
                $this->store->finishBackgroundAction($backgroundKey, (bool) $result['ok']);
            }
            $this->store->finishReceipt($conversationId, $callId, $result);

            return $result;
        } catch (Throwable $error) {
            if (in_array($name, ['searchWeb', 'researchWeb'], true)) {
                // A search is read-only, so a failed lookup is safe to finish and
                // explain. WebSearch emits only fixed, credential-free errors.
                $result = ['ok' => false, 'text' => $error instanceof \RuntimeException || $error instanceof \InvalidArgumentException
                    ? $error->getMessage() : 'Web search could not be completed.'];
                $this->store->finishReceipt($conversationId, $callId, $result);

                return $result;
            }
            // A pending receipt is intentionally left pending after an uncertain failure.
            // Replays of the same call ID therefore cannot perform the write twice.
            $result = ['ok' => false, 'text' => $error instanceof AuthorizationException
                ? 'This action is not permitted in the current project or account state.'
                : 'The action may not have completed and was not retried. Please review the activity before trying again.'];

            return $result;
        }
    }

    /** @return list<ToolDefinition> */
    private function modelDefinitions(?int $projectId, int $actorId, string $source): array
    {
        $writable = $projectId !== null && ($source !== 'background'
            || $this->projectAgent->isBackgroundRunnable($projectId, $actorId));
        $definitions = [];
        foreach ($this->catalog->definitions() as $spec) {
            // The catalog's full-scene replacement remains available to a
            // trusted MCP client. The model only sees a bounded preview, so
            // it must use revision-checked patches to avoid deleting unseen
            // elements from a large board.
            if ($spec['name'] === 'saveWhiteboardScene') {
                continue;
            }
            if ($source === 'background' && in_array($spec['name'], ['searchWeb', 'researchWeb'], true)) {
                continue;
            }
            if (! $writable && $spec['effect'] !== 'read') {
                continue;
            }
            $definitions[] = new ToolDefinition(
                (string) $spec['name'],
                (string) $spec['description'],
                $spec['input_schema'],
                $spec['effect'] === 'read',
                $spec['effect'] === 'delete',
            );
        }

        return $definitions;
    }

    /** @return list<array<string,mixed>> */
    private function providerMessages(int $conversationId, bool $taskToolAvailable): array
    {
        $messages = [];
        $pendingToolCalls = [];
        $closeInterruptedCalls = static function () use (&$messages, &$pendingToolCalls): void {
            foreach ($pendingToolCalls as $callId => $name) {
                $messages[] = [
                    'role' => 'tool',
                    'content' => 'An earlier tool action may have run before interruption. It was not retried; inspect activity.',
                    'tool_call_id' => $callId,
                    'name' => $name,
                    'is_error' => true,
                ];
            }
            $pendingToolCalls = [];
        };
        $turns = $this->store->turns($conversationId);
        // The recent-window boundary may cut through a tool round. Begin at
        // the next user turn so provider protocols always receive a coherent
        // assistant/tool-call sequence and the newest request stays in view.
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }
        foreach ($turns as $turn) {
            $meta = $turn['metadata_json'] === null ? [] : json_decode((string) $turn['metadata_json'], true, 64, JSON_THROW_ON_ERROR);
            if ($turn['role'] !== 'tool' && $pendingToolCalls !== []) {
                $closeInterruptedCalls();
            }
            $staleCapabilityClaim = $taskToolAvailable && $turn['role'] === 'assistant'
                && empty($meta['tool_calls']) && self::falseTaskToolClaim((string) $turn['content']);
            $message = ['role' => $turn['role'], 'content' => $staleCapabilityClaim
                ? 'An earlier assistant claim about missing task tools was outdated. The current tool list is authoritative.'
                : $turn['content']];
            if ($turn['role'] === 'assistant' && ! empty($meta['tool_calls'])) {
                $message['tool_calls'] = $meta['tool_calls'];
                foreach ($meta['tool_calls'] as $call) {
                    if (is_array($call) && is_string($call['id'] ?? null) && is_string($call['name'] ?? null)) {
                        $pendingToolCalls[$call['id']] = $call['name'];
                    }
                }
            }
            if (! $staleCapabilityClaim && $turn['role'] === 'assistant' && is_string($meta['reasoning_content'] ?? null)) {
                $message['reasoning_content'] = $meta['reasoning_content'];
            }
            if ($turn['role'] === 'tool') {
                $message['tool_call_id'] = $meta['tool_call_id'];
                $message['name'] = $meta['name'];
                $message['is_error'] = $meta['is_error'] ?? false;
                unset($pendingToolCalls[$meta['tool_call_id']]);
            }
            $messages[] = $message;
        }
        if ($pendingToolCalls !== []) {
            $closeInterruptedCalls();
        }

        // Keep whole user-led rounds rather than a raw 300-row window. This
        // bounds provider payloads without dropping a tool result from its
        // assistant call; the complete transcript remains in the database.
        $rounds = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'user' || $rounds === []) {
                $rounds[] = [];
            }
            $rounds[array_key_last($rounds)][] = $message;
        }
        $kept = [];
        $bytes = 0;
        for ($index = count($rounds) - 1; $index >= 0; $index--) {
            $roundBytes = strlen(json_encode($rounds[$index], JSON_THROW_ON_ERROR));
            if ($kept !== [] && $bytes + $roundBytes > self::MAX_CONTEXT_BYTES) {
                break;
            }
            array_unshift($kept, $rounds[$index]);
            $bytes += $roundBytes;
        }

        return array_merge(...$kept);
    }

    private static function falseTaskToolClaim(string $text): bool
    {
        return preg_match('/\b(?:tasks?|to-?dos?|tâches?)\b/iu', $text) === 1
            && preg_match('/\b(?:tools?|toolset|capabilit(?:y|ies)|outils?|fonctionnalit[eé]s?)\b/iu', $text) === 1
            && preg_match('/\b(?:no|none|not|cannot|can.t|unable|missing|aucun|pas|impossible|manque)\b/iu', $text) === 1
            && preg_match('/\b(?:permission|permitted|authorized|access|autorisation|acc[eè]s)\b/iu', $text) !== 1;
    }

    private function recordQuestionIfNeeded(int $conversationId, ?int $projectId, string $text): void
    {
        $question = self::withoutQuestionPrefix($text);
        if ($question !== null && $question !== '') {
            $this->store->addQuestion($conversationId, $projectId, $question);
        }
    }

    private static function withoutQuestionPrefix(string $text): ?string
    {
        if (preg_match('/^\s*(?:\*\*)?NEEDS_INPUT:(?:\*\*)?\s*/u', $text, $match) !== 1) {
            return null;
        }

        return trim(substr($text, strlen($match[0])));
    }

    private function locale(int $actorId, string $source): string
    {
        if ($source === 'background') {
            return $this->store->actorLocale($actorId);
        }
        $locale = (string) (session('usersettings.language') ?? session('userdata.settings.language') ?? 'fr-CH');

        return in_array($locale, ['en-US', 'fr-CH'], true) ? $locale : 'fr-CH';
    }

    private function assertProjectView(?int $projectId, int $actorId, string $source = 'interactive'): void
    {
        if ($projectId !== null) {
            $this->dispatcher->authorize('getProject', ['projectId' => $projectId], $actorId, $projectId, $source);
        }
    }

    /** Stable key order makes equivalent model JSON share one background claim. */
    private function canonicalArguments(array $arguments): array
    {
        if (! array_is_list($arguments)) {
            ksort($arguments);
        }
        foreach ($arguments as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalArguments($value);
            }
        }
        unset($value);

        return $arguments;
    }

    /** Project-shared activity uses trusted labels, never conversational text. */
    private function activityOutcome(string $name, array $result): string
    {
        if (! ($result['ok'] ?? false)) {
            return 'The '.$name.' action did not complete.';
        }
        $labels = [
            'createGoalboard' => 'Goal board created.',
            'createGoal' => 'Goal created.',
            'editGoal' => 'Goal updated.',
            'addTask' => 'Task created.',
            'bulkAddTasks' => 'Tasks created.',
            'bulkEditTasks' => 'Tasks updated.',
            'bulkScheduleTasks' => 'Tasks scheduled.',
            'addMilestone' => 'Milestone created.',
            'addSubtask' => 'Subtask created.',
            'editTask' => 'Task updated.',
            'editMilestone' => 'Milestone updated.',
            'scheduleTaskOnCalendar' => 'Task scheduled on the calendar.',
            'logTime' => 'Time entry recorded.',
            'startTimer' => 'Task timer started.',
            'createWhiteboard' => 'Whiteboard created.',
            'createSwotBlueprint' => 'SWOT blueprint created.',
            'addSwotItems' => 'SWOT blueprint updated.',
            'patchWhiteboardScene' => 'Whiteboard updated.',
            'restoreWhiteboardRevision' => 'Whiteboard revision restored.',
        ];

        return $labels[$name] ?? 'Project action completed.';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentConversation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'projectId' => $row['project_id'] === null ? null : (int) $row['project_id'],
            'title' => (string) $row['title'],
            'state' => (string) $row['state'],
            'pagePath' => $row['page_path'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentTurn(array $row): array
    {
        $content = (string) $row['content'];
        $meta = $row['metadata_json'] === null ? [] : json_decode((string) $row['metadata_json'], true, 64, JSON_THROW_ON_ERROR);
        if ($row['role'] === 'assistant') {
            $content = self::withoutQuestionPrefix($content) ?? $content;
        }

        return [
            'id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'content' => $content,
            'interrupted' => (bool) ($meta['interrupted'] ?? false),
            'createdAt' => (string) $row['created_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentDraft(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'projectId' => (int) $row['project_id'],
            'toolName' => (string) $row['tool_name'],
            'arguments' => json_decode((string) $row['arguments_json'], true, 64, JSON_THROW_ON_ERROR),
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'createdAt' => (string) $row['created_at'],
        ];
    }
}
