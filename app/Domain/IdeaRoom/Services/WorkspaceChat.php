<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Services;

use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Core\Language;
use Leantime\Domain\IdeaRoom\AI\AiProvider;
use Leantime\Domain\IdeaRoom\AI\CanvasPatch;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\ProviderFactory;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\ToolDefinition;
use Leantime\Domain\IdeaRoom\AI\ToolCall;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Leantime\Domain\IdeaRoom\Tools\IdeaRoomToolRegistry;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use Throwable;

/** The only bridge between provider-requested calls and the permission-checked registry. */
final class WorkspaceChat
{
    private const DEFAULT_MAX_TOOL_TURNS = 5;
    private const COMMUNICATION_TOOLS = ['addComment', 'addProjectStatusUpdate'];
    private const UNSAFE_AUTONOMOUS_TOOLS = ['addProject', 'addEvent', 'editEvent', 'deleteEvent', 'stopTimer'];

    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly RoomRepository $rooms,
        private readonly IdeaRoomToolRegistry $tools,
        private readonly ?AiProvider $provider = null,
        private readonly ?IdeaGraph $graph = null,
        private readonly ?ProjectAgent $projectAgent = null,
    ) {}

    /** @return array<string, mixed> */
    public function state(int $roomId): array
    {
        $room = $this->ideaRooms->room($roomId);
        $this->staleLegacyActions($roomId);

        return [
            'room' => $room,
            'messages' => array_values(array_filter($this->rooms->messages($roomId),
                static fn (array $message): bool => in_array($message['role'], ['user', 'assistant'], true)
                    && trim((string) $message['content']) !== '')),
            'pendingActions' => [],
            'staleActions' => $this->rooms->actionsByStatus($roomId, 'stale'),
            'drafts' => $this->rooms->actionsByStatus($roomId, 'draft'),
            'events' => $this->rooms->recentEvents($roomId),
            'generation' => $this->rooms->generation($roomId),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pendingActions(int $roomId): array
    {
        $this->ideaRooms->room($roomId);
        $this->staleLegacyActions($roomId);

        return [];
    }

    /** @return list<array<string, mixed>> */
    public function events(int $roomId, int $afterId): array
    {
        $this->ideaRooms->room($roomId);

        return $this->rooms->events($roomId, $afterId);
    }

    public function startTurn(int $roomId, string $content, ?int $expectedVersion = null): void
    {
        if ($this->provider() === null) {
            throw new InvalidArgumentException('An AI provider must be configured before chatting.');
        }
        $content = trim($content);
        if (mb_strlen($content) > 10000) {
            throw new InvalidArgumentException('Write a message in 1–10000 characters.');
        }

        $this->rooms->transaction(function () use ($roomId, $content, $expectedVersion): void {
            $room = $this->ideaRooms->room($roomId);
            if (! $this->ideaRooms->canChat($room)) {
                throw new AuthorizationException;
            }
            $locked = $this->rooms->lock($roomId) ?? throw new NotFoundException;
            $this->staleLegacyActions($roomId);
            if ($expectedVersion !== null && (int) ($locked['graph_version'] ?? 0) !== $expectedVersion) {
                throw new GraphConflictException;
            }
            $generation = $this->rooms->generation($roomId);
            if (($generation['status'] ?? null) === 'running') {
                throw new InvalidArgumentException('An assistant turn is already in progress.');
            }
            if ($content === '') {
                $messages = $this->rooms->messages($roomId);
                $last = $messages === [] ? null : $messages[array_key_last($messages)];
                if ($generation !== null || ($last['role'] ?? null) !== 'user') {
                    throw new InvalidArgumentException('Write a message before starting another assistant turn.');
                }
            } else {
                $message = $this->rooms->addMessage($roomId, 'user', $content);
                $this->rooms->addEvent($roomId, 'message.started', ['message' => $message]);
            }
            $this->rooms->setGeneration($roomId, 'running', resetTurns: true);
        });
    }

    public function retryTurn(int $roomId): void
    {
        $this->rooms->transaction(function () use ($roomId): void {
            $room = $this->ideaRooms->room($roomId);
            if (! $this->ideaRooms->canChat($room)) {
                throw new AuthorizationException;
            }
            $this->rooms->lock($roomId);
            $this->staleLegacyActions($roomId);
            if (! in_array($this->rooms->generation($roomId)['status'] ?? null, ['failed', 'cancelled'], true)) {
                throw new InvalidArgumentException('There is no failed assistant turn to retry.');
            }
            $this->rooms->setGeneration($roomId, 'running', resetTurns: true);
        });
    }

    /** @param callable(array<string, mixed>): void|null $emit */
    public function runTurn(int $roomId, ?callable $emit = null): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            $this->fail($roomId, $emit, 'The AI provider is not configured.');

            return;
        }

        $seenCalls = $this->seenCallsSinceLastUser($roomId);
        try {
            while (true) {
                if ((int) ($this->rooms->generation($roomId)['tool_turns'] ?? 0) >= $this->maxToolTurns()) {
                    $this->fail($roomId, $emit, 'The assistant reached the tool-call limit.');

                    return;
                }
                if ($this->cancelled($roomId)) {
                    $this->fail($roomId, $emit, 'Generation was cancelled.', 'cancelled');

                    return;
                }

                $room = $this->ideaRooms->room($roomId);
                if (! $this->ideaRooms->canChat($room)) {
                    throw new AuthorizationException;
                }
                $this->publish($roomId, 'message.started', ['message' => ['role' => 'assistant', 'content' => '']], $emit);
                $reply = $provider->streamTurn($this->chatRequest($room), function (string $delta) use ($roomId, $emit): void {
                    $this->publish($roomId, 'message.delta', ['delta' => $delta], $emit);
                });
                $this->rooms->incrementToolTurns($roomId);
                if ($this->cancelled($roomId)) {
                    $this->fail($roomId, $emit, 'Generation was cancelled.', 'cancelled');

                    return;
                }

                $toolCalls = $reply->toolCalls;
                if (count($toolCalls) > 4) {
                    throw new InvalidArgumentException('Too many tool requests.');
                }
                foreach ($toolCalls as $call) {
                    $fingerprint = $this->fingerprint($call->name, $call->arguments);
                    if (isset($seenCalls[$fingerprint])) {
                        throw new InvalidArgumentException('The assistant repeated a tool request.');
                    }
                    $seenCalls[$fingerprint] = true;
                    if ($call->name !== 'proposeCanvasPatch' || ! $this->graphEnabled()) {
                        $this->tools->validate($call->name, $call->arguments);
                    }
                }

                $assistant = $this->rooms->addMessage($roomId, 'assistant', $reply->text, [
                    'tool_calls' => array_map(static fn ($call): array => [
                        'id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments,
                    ], $toolCalls),
                    'reasoning_content' => $reply->reasoningContent,
                ]);
                if ($toolCalls === []) {
                    $this->rooms->setGeneration($roomId, 'completed');
                    $this->publish($roomId, 'message.completed', ['message' => $assistant], $emit);

                    return;
                }

                foreach ($toolCalls as $call) {
                    if ($call->name === 'proposeCanvasPatch' && $this->graphEnabled()) {
                        $this->publish($roomId, 'tool.requested', ['tool' => [
                            'id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments,
                        ]], $emit);
                        try {
                            $proposal = CanvasPatch::fromToolArguments($call->arguments);
                            $previousNodeIds = array_column($this->graphService()->graph($roomId)['graph']['nodes'], 'id');
                            $saved = $this->graphService()->applyPatch($roomId, $proposal->patch, $proposal->inspirationCards, 'chat', '',
                                (int) $room['graph_version'], (int) $room['plan_version']);
                            $newNodeIds = array_values(array_diff(array_column($saved['graph']['nodes'], 'id'), $previousNodeIds));
                            $newNodes = $newNodeIds === [] ? 'No new nodes were created by this patch.'
                                : 'New node IDs created by this patch: '.implode(', ', array_map(static fn (int $id): string => '#'.$id, $newNodeIds)).'.';
                            $this->recordReadResult($roomId, $call->id, $call->name, [
                                'ok' => true,
                                'text' => 'Canvas change applied and saved in history #'.$saved['historyEntry']['id'].'. '
                                    .$newNodes.' The next current_graph is the saved post-change state. '
                                    .'A newly created node in it is not a pre-existing duplicate; only a separate node with a different ID could be one. '
                                    .'This change can be restored from the Idea Room history.',
                            ], $emit);
                        } catch (InvalidArgumentException $error) {
                            $this->recordReadResult($roomId, $call->id, $call->name, [
                                'ok' => false,
                                'text' => 'Canvas change rejected: '.$error->getMessage().' New nodes need a unique clientId, a supported type, title, and numeric x/y coordinates; use content instead of text. Correct the patch and retry.',
                            ], $emit);
                        } catch (GraphConflictException) {
                            $this->recordReadResult($roomId, $call->id, $call->name, [
                                'ok' => false,
                                'text' => 'Canvas change rejected because the room changed. Reload current canvas and plan state, then retry.',
                            ], $emit);
                        } catch (Throwable) {
                            $this->recordReadResult($roomId, $call->id, $call->name, [
                                'ok' => false,
                                'text' => 'The canvas change was invalid or could not be saved.',
                            ], $emit);
                        }

                        continue;
                    }
                    $classification = $this->tools->classify($call->name);
                    $this->publish($roomId, 'tool.requested', ['tool' => [
                        'id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments,
                    ]], $emit);
                    if ($this->cancelled($roomId)) {
                        $this->recordReadResult($roomId, $call->id, $call->name,
                            ['ok' => false, 'text' => 'Action cancelled.'], $emit);

                        continue;
                    }
                    try {
                        $liveRoom = $this->ideaRooms->room($roomId); // Recheck project access before each call.
                    } catch (AuthorizationException|NotFoundException) {
                        $this->recordReadResult($roomId, $call->id, $call->name,
                            ['ok' => false, 'text' => 'Access to this workspace is no longer available.'], $emit);

                        continue;
                    }
                    if ($classification['write']) {
                        if ((int) ($liveRoom['project_id'] ?? 0) !== (int) ($room['project_id'] ?? 0)
                            || ! $this->canRunAgent($liveRoom)
                            || $classification['destructive']
                            || in_array($call->name, self::UNSAFE_AUTONOMOUS_TOOLS, true)) {
                            $this->recordReadResult($roomId, $call->id, $call->name,
                                ['ok' => false, 'text' => 'This action is unavailable for the current project or agent state. No change was made.'], $emit);

                            continue;
                        }
                        if (in_array($call->name, self::COMMUNICATION_TOOLS, true)) {
                            $this->saveCommunicationDraft($roomId, $liveRoom, $call, $reply->text, $emit);
                        } else {
                            $this->executeAutonomousWrite($roomId, $liveRoom, $call, $classification, $reply->text, $emit);
                        }

                        continue;
                    }

                    $this->publish($roomId, 'tool.started', ['tool' => ['id' => $call->id, 'name' => $call->name]], $emit);
                    try {
                        $result = $this->tools->execute($call->name, $call->arguments);
                    } catch (Throwable) {
                        $result = ['ok' => false, 'text' => 'The tool could not be completed or access was denied.'];
                    }
                    $this->recordReadResult($roomId, $call->id, $call->name, $result, $emit);
                }

                if ($this->cancelled($roomId)) {
                    $this->fail($roomId, $emit, 'Generation was cancelled.', 'cancelled');

                    return;
                }
            }
        } catch (ProviderException $error) {
            // The provider adapter's messages contain no response bodies,
            // credentials, tool arguments, or transcript contents.
            $this->fail($roomId, $emit, $error->getMessage());
        } catch (Throwable) {
            $this->fail($roomId, $emit, 'The assistant could not complete this turn.');
        }
    }

    /** @return array<string, mixed> */
    public function resolveAction(int $roomId, int $actionId, bool $confirm, ?callable $emit = null): array
    {
        $this->ideaRooms->room($roomId);
        $this->staleLegacyActions($roomId);
        if ($confirm) {
            throw new InvalidArgumentException('Old action approvals are stale. Rerun the request instead.');
        }
        $this->rooms->transaction(function () use ($roomId, $actionId): void {
            $action = $this->rooms->action($roomId, $actionId, true) ?? throw new NotFoundException;
            if ($action['status'] === 'stale') {
                $this->rooms->updateAction($roomId, $actionId, 'discarded', ['ok' => false, 'text' => 'Stale request discarded.']);
                $this->publish($roomId, 'tool.stale_discarded', ['action' => ['id' => $actionId, 'status' => 'discarded']]);
            }
        });

        return $this->state($roomId);
    }

    /** Explicit PM review of a communication draft; it can never be sent by an AI tool turn. */
    public function publishDraft(int $roomId, int $actionId): array
    {
        $action = $this->rooms->transaction(function () use ($roomId, $actionId): array {
            $room = $this->ideaRooms->room($roomId);
            if (! $this->ideaRooms->canChat($room) || (int) ($room['project_id'] ?? 0) < 1) {
                throw new AuthorizationException;
            }
            $this->rooms->lock($roomId);
            $action = $this->rooms->action($roomId, $actionId, true) ?? throw new NotFoundException;
            if ($action['status'] !== 'draft') {
                return $action; // A replay never sends twice.
            }
            if (! in_array($action['tool_name'], self::COMMUNICATION_TOOLS, true)) {
                throw new AuthorizationException;
            }
            $this->tools->authorizeForProject((string) $action['tool_name'], $action['arguments'], (int) $room['project_id']);
            $this->rooms->updateAction($roomId, $actionId, 'executing');

            return $action;
        });
        if ($action['status'] !== 'draft') {
            return $this->state($roomId);
        }

        try {
            $this->rooms->transaction(function () use ($roomId, $actionId, $action): void {
                $room = $this->ideaRooms->room($roomId);
                if (! $this->ideaRooms->canChat($room) || (int) ($room['project_id'] ?? 0) < 1) {
                    throw new AuthorizationException;
                }
                $current = $this->rooms->action($roomId, $actionId, true) ?? throw new NotFoundException;
                if ($current['status'] !== 'executing') {
                    return;
                }
                $this->tools->authorizeForProject((string) $action['tool_name'], $action['arguments'], (int) $room['project_id']);
                $result = $this->tools->execute((string) $action['tool_name'], $action['arguments'], true);
                $status = $result['ok'] ? 'completed' : 'failed';
                $this->rooms->updateAction($roomId, $actionId, $status, $result);
                $this->publish($roomId, 'draft.published', ['action' => ['id' => $actionId, 'status' => $status]]);
                $this->agent()->recordActivity((int) $room['project_id'], (int) session('userdata.id'), [
                    'action' => (string) $action['tool_name'],
                    'rationale' => 'Published a communication draft after PM review.',
                    'outcome' => $result['text'],
                    'status' => $status,
                    'idempotencyKey' => 'idea-room-draft-'.$actionId,
                ]);
            });
        } catch (Throwable) {
            // The receipt stays "executing" after an uncertain outcome; never auto-retry a send.
            throw new InvalidArgumentException('The draft outcome needs review before it can be retried.');
        }

        return $this->state($roomId);
    }

    /** @return array<string, mixed> */
    public function discardDraft(int $roomId, int $actionId): array
    {
        $this->rooms->transaction(function () use ($roomId, $actionId): void {
            $room = $this->ideaRooms->room($roomId);
            if (! $this->ideaRooms->canChat($room)) {
                throw new AuthorizationException;
            }
            $action = $this->rooms->action($roomId, $actionId, true) ?? throw new NotFoundException;
            if ($action['status'] !== 'draft') {
                return;
            }
            $this->rooms->updateAction($roomId, $actionId, 'discarded', ['ok' => false, 'text' => 'Draft discarded.']);
            $this->publish($roomId, 'draft.discarded', ['action' => ['id' => $actionId, 'status' => 'discarded']]);
        });

        return $this->state($roomId);
    }

    /** @return array<string, bool> */
    public function cancel(int $roomId): array
    {
        $this->rooms->transaction(function () use ($roomId): void {
            $room = $this->ideaRooms->room($roomId);
            if (! $this->ideaRooms->canChat($room)) {
                throw new AuthorizationException;
            }
            $this->rooms->lock($roomId);
            $this->rooms->requestCancel($roomId);
            if (($this->rooms->generation($roomId)['status'] ?? null) === 'awaiting_confirmation') {
                foreach ($this->rooms->pendingActions($roomId) as $pending) {
                    $action = $this->rooms->action($roomId, (int) $pending['id'], true);
                    if ($action === null || $action['status'] !== 'pending') {
                        continue;
                    }
                    $this->rooms->updateAction($roomId, (int) $action['id'], 'cancelled');
                    $this->addActionResult($roomId, $action, ['ok' => false, 'text' => 'Action cancelled.'], 'cancelled');
                }
                $this->rooms->setGeneration($roomId, 'cancelled', true);
            }
        });

        return ['cancelled' => true];
    }

    /** @param array<string, mixed> $room */
    private function chatRequest(array $room): ChatRequest
    {
        $messages = [];
        $recent = array_slice($this->rooms->messages((int) $room['id']), -40);
        while ($recent !== [] && $recent[0]['role'] !== 'user') {
            array_shift($recent);
        }
        foreach ($recent as $message) {
            $entry = ['role' => (string) $message['role'], 'content' => (string) $message['content']];
            $metadata = $message['metadata'] ?? [];
            if ($entry['role'] === 'assistant' && ! empty($metadata['tool_calls'])) {
                $entry['tool_calls'] = $metadata['tool_calls'];
            }
            if ($entry['role'] === 'assistant' && is_string($metadata['reasoning_content'] ?? null)) {
                $entry['reasoning_content'] = $metadata['reasoning_content'];
            }
            if ($entry['role'] === 'tool') {
                $entry['tool_call_id'] = (string) ($metadata['tool_call_id'] ?? '');
                $entry['name'] = (string) ($metadata['name'] ?? '');
                $entry['is_error'] = (bool) ($metadata['is_error'] ?? false);
            }
            $messages[] = $entry;
        }

        $canRunAgent = $this->canRunAgent($room);
        $definitions = array_map(static fn (array $definition): ToolDefinition => new ToolDefinition(
            $definition['name'], $definition['description'], $definition['input_schema'],
            ! $definition['write'], $definition['destructive'],
        ), array_values(array_filter($this->tools->definitions(), static fn (array $definition): bool =>
            ! $definition['write'] || ($canRunAgent
                && ! $definition['destructive']
                && ! in_array($definition['name'], self::UNSAFE_AUTONOMOUS_TOOLS, true)))));
        $graph = [];
        $citations = [];
        $mode = 'explore'; // Legacy request shape; the UI no longer has an Explore/Execute switch.
        if ($this->graphEnabled()) {
            $graph = $this->graphService()->graph((int) $room['id'])['graph'];
            $sourceIds = [];
            foreach ($graph['nodes'] ?? [] as $node) {
                if (($node['type'] ?? null) === 'source') {
                    $sourceId = $node['metadata']['sourceId'] ?? null;
                    if (is_numeric($sourceId)) {
                        $sourceIds[(int) $sourceId] = true;
                    }
                }
            }
            foreach ($this->graphService()->sources((int) $room['id']) as $source) {
                if (isset($sourceIds[(int) ($source['id'] ?? 0)])) {
                    $citations[] = $source;
                }
                if (count($citations) >= 20) {
                    break;
                }
            }
            if ($this->ideaRooms->canEdit($room)) {
                $definitions[] = CanvasPatch::toolDefinition();
            }
        }
        $locale = (string) (session('usersettings.language') ?: session('companysettings.language') ?: 'en-US');
        if (! in_array($locale, Language::SUPPORTED_LANGUAGES, true)) {
            $locale = 'en-US';
        }

        return new ChatRequest($messages, $room['plan'], (string) ($room['project_name'] ?? ''), $locale, $definitions,
            $graph, $citations, $mode);
    }

    private function graphEnabled(): bool
    {
        $raw = $_ENV['JULIANNA_IDEA_GRAPH_ENABLED'] ?? getenv('JULIANNA_IDEA_GRAPH_ENABLED');

        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function graphService(): IdeaGraph
    {
        return $this->graph ?? app()->make(IdeaGraph::class);
    }

    private function cancelled(int $roomId): bool
    {
        return (bool) ($this->rooms->generation($roomId)['cancel_requested'] ?? false);
    }

    /** Old approvals are historical context, not authority to run a write. */
    private function staleLegacyActions(int $roomId): void
    {
        $this->rooms->transaction(function () use ($roomId): void {
            $this->rooms->lock($roomId);
            foreach ($this->rooms->stalePendingActions($roomId) as $action) {
                $this->addActionResult($roomId, $action, [
                    'ok' => false,
                    'text' => 'This old approval was not executed. Rerun the request if it is still wanted.',
                ], 'stale');
            }
            if (($this->rooms->generation($roomId)['status'] ?? null) === 'awaiting_confirmation') {
                $this->rooms->setGeneration($roomId, 'failed');
            }
        });
    }

    /** @param array<string, mixed> $room */
    private function canRunAgent(array $room): bool
    {
        $projectId = (int) ($room['project_id'] ?? 0);
        $actorId = (int) session('userdata.id');
        if ($projectId < 1 || $actorId < 1) {
            return false;
        }

        try {
            return $this->agent()->isRunnable($projectId, $actorId);
        } catch (Throwable) {
            return false; // Fail closed when settings or access cannot be checked.
        }
    }

    private function agent(): ProjectAgent
    {
        return $this->projectAgent ?? app()->make(ProjectAgent::class);
    }

    private function actionKey(int $roomId, ToolCall $call): string
    {
        return hash('sha256', "idea-room:{$roomId}:".$call->id);
    }

    /**
     * @param array<string, mixed> $room
     * @param callable(array<string, mixed>): void|null $emit
     */
    private function saveCommunicationDraft(int $roomId, array $room, ToolCall $call, string $rationale, ?callable $emit): void
    {
        try {
            $this->rooms->transaction(function () use ($roomId, $room, $call, $rationale, $emit): void {
                $live = $this->ideaRooms->room($roomId);
                $projectId = (int) ($live['project_id'] ?? 0);
                if ($projectId !== (int) $room['project_id'] || ! $this->canRunAgent($live)) {
                    throw new AuthorizationException;
                }
                $this->tools->authorizeForProject($call->name, $call->arguments, $projectId);
                $action = $this->rooms->addAction($roomId, $call->id, $call->name, $call->arguments, false,
                    'draft', $this->actionKey($roomId, $call));
                if (! $action['_created']) {
                    throw new InvalidArgumentException('The communication draft was already saved.');
                }
                unset($action['_created']);
                $this->addActionResult($roomId, $action, [
                    'ok' => true,
                    'text' => 'Communication saved as a draft for PM review. It was not published.',
                ], 'draft', $emit);
                $this->agent()->recordActivity($projectId, (int) session('userdata.id'), [
                    'action' => $call->name,
                    'rationale' => trim($rationale) !== '' ? $rationale : 'Prepared a communication draft at the user’s request.',
                    'outcome' => 'Saved as a draft; not published.',
                    'status' => 'draft',
                    'idempotencyKey' => $this->actionKey($roomId, $call),
                ]);
            });
        } catch (Throwable) {
            $this->recordReadResult($roomId, $call->id, $call->name,
                ['ok' => false, 'text' => 'The communication could not be saved as a draft or access was denied. Nothing was published.'], $emit);
        }
    }

    /**
     * @param array<string, mixed> $room
     * @param array{write:bool,destructive:bool} $classification
     * @param callable(array<string, mixed>): void|null $emit
     */
    private function executeAutonomousWrite(int $roomId, array $room, ToolCall $call, array $classification, string $rationale, ?callable $emit): void
    {
        $action = null;
        try {
            // Commit a stable receipt before entering any domain code. A crash leaves it
            // unresolved for human inspection, rather than silently re-running a write.
            $action = $this->rooms->transaction(function () use ($roomId, $room, $call, $classification): array {
                $live = $this->ideaRooms->room($roomId);
                $projectId = (int) ($live['project_id'] ?? 0);
                if ($projectId !== (int) $room['project_id'] || ! $this->canRunAgent($live)) {
                    throw new AuthorizationException;
                }
                $this->tools->authorizeForProject($call->name, $call->arguments, $projectId);

                return $this->rooms->addAction($roomId, $call->id, $call->name, $call->arguments,
                    $classification['destructive'], 'executing', $this->actionKey($roomId, $call));
            });
            if (! $action['_created']) {
                $this->recordReadResult($roomId, $call->id, $call->name,
                    ['ok' => false, 'text' => 'This action already has a recorded outcome. It was not run again.'], $emit);

                return;
            }
            unset($action['_created']);
            $this->publish($roomId, 'tool.started', ['tool' => ['id' => $call->id, 'name' => $call->name]], $emit);
            $this->rooms->transaction(function () use ($roomId, $room, $call, $action, $rationale, $emit): void {
                $live = $this->ideaRooms->room($roomId);
                $projectId = (int) ($live['project_id'] ?? 0);
                if ($projectId !== (int) $room['project_id'] || ! $this->canRunAgent($live)) {
                    throw new AuthorizationException;
                }
                $locked = $this->rooms->action($roomId, (int) $action['id'], true) ?? throw new NotFoundException;
                if ($locked['status'] !== 'executing') {
                    throw new InvalidArgumentException('The action has already been handled.');
                }
                $this->tools->authorizeForProject($call->name, $call->arguments, $projectId);
                $result = $this->tools->execute($call->name, $call->arguments, true);
                $status = $result['ok'] ? 'completed' : 'failed';
                $this->rooms->updateAction($roomId, (int) $action['id'], $status, $result);
                $this->addActionResult($roomId, $action, $result, $status, $emit);
                $this->agent()->recordActivity($projectId, (int) session('userdata.id'), [
                    'action' => $call->name,
                    'rationale' => trim($rationale) !== '' ? $rationale : 'Completed a permitted workspace request.',
                    'outcome' => $result['text'],
                    'status' => $status,
                    'idempotencyKey' => $this->actionKey($roomId, $call),
                ]);
            });
        } catch (Throwable) {
            if (is_array($action) && ($action['_created'] ?? true)) {
                // The domain transaction rolled back, but an external side effect may
                // still have occurred. Do not assume it is safe to retry automatically.
                $this->rooms->updateAction($roomId, (int) $action['id'], 'needs_review', [
                    'ok' => false, 'text' => 'Outcome uncertain; inspect before retrying.',
                ]);
            }
            $this->recordReadResult($roomId, $call->id, $call->name,
                ['ok' => false, 'text' => 'The action could not be completed or access was denied. Check activity before retrying.'], $emit);
        }
    }

    private function maxToolTurns(): int
    {
        $raw = $_ENV['JULIANNA_AI_MAX_TOOL_TURNS'] ?? getenv('JULIANNA_AI_MAX_TOOL_TURNS');
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 1 && $value <= 10 ? $value : self::DEFAULT_MAX_TOOL_TURNS;
    }

    private function provider(): ?AiProvider
    {
        return $this->provider ?? ProviderFactory::forCurrentSession();
    }

    /** @return array<string, true> */
    private function seenCallsSinceLastUser(int $roomId): array
    {
        $seen = [];
        foreach (array_reverse($this->rooms->messages($roomId)) as $message) {
            if ($message['role'] === 'user') {
                break;
            }
            foreach (($message['metadata']['tool_calls'] ?? []) as $call) {
                if (is_array($call) && is_string($call['name'] ?? null) && is_array($call['arguments'] ?? null)) {
                    $seen[$this->fingerprint($call['name'], $call['arguments'])] = true;
                }
            }
        }

        return $seen;
    }

    /** @param array<string, mixed> $arguments */
    private function fingerprint(string $name, array $arguments): string
    {
        return hash('sha256', $name."\0".json_encode($this->canonicalize($arguments), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            $item = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $action
     * @param  array{ok: bool, text: string}  $result
     */
    private function addActionResult(int $roomId, array $action, array $result, string $status): void
    {
        $this->rooms->addMessage($roomId, 'tool', $result['text'], [
            'tool_call_id' => (string) $action['tool_call_id'],
            'name' => (string) $action['tool_name'],
            'is_error' => ! $result['ok'],
        ]);
        $this->publish($roomId, 'tool.result', [
            'action' => ['id' => $action['id'], 'status' => $status], 'result' => $result,
        ]);
    }

    /** @param array{ok: bool, text: string} $result
     * @param  callable(array<string, mixed>): void|null  $emit
     */
    private function recordReadResult(int $roomId, string $callId, string $name, array $result, ?callable $emit): void
    {
        $this->rooms->addMessage($roomId, 'tool', $result['text'], [
            'tool_call_id' => $callId, 'name' => $name, 'is_error' => ! $result['ok'],
        ]);
        $this->publish($roomId, 'tool.result', ['tool' => ['id' => $callId, 'name' => $name], 'result' => $result], $emit);
    }

    /** @param callable(array<string, mixed>): void|null $emit
     * @param  array<string, mixed>  $data
     */
    private function publish(int $roomId, string $name, array $data, ?callable $emit = null): void
    {
        $event = $this->rooms->addEvent($roomId, $name, $data);
        if ($emit !== null) {
            $emit($event);
        }
    }

    /** @param callable(array<string, mixed>): void|null $emit */
    private function fail(int $roomId, ?callable $emit, string $safeMessage, string $status = 'failed'): void
    {
        foreach ($this->rooms->pendingActions($roomId) as $action) {
            $this->rooms->updateAction($roomId, (int) $action['id'], 'cancelled');
            $this->addActionResult($roomId, $action, ['ok' => false, 'text' => 'Action cancelled because the assistant turn stopped.'], 'cancelled');
        }
        $this->rooms->setGeneration($roomId, $status, $status === 'cancelled');
        $this->publish($roomId, 'message.error', ['error' => $safeMessage], $emit);
    }
}
