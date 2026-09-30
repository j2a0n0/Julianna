<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Repositories;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\ProjectAgent\Repositories\ProjectAgentRepository;

/** Durable in-app agent state. Tool authority is never inferred from these rows. */
final class AgentRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ProjectAgentRepository $projects,
    ) {}

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    public function activeActor(int $actorId): bool
    {
        if ($actorId < 1) {
            return false;
        }

        return $this->db->table('julianna_auth_accounts')->where('user_id', $actorId)
            ->where('state', 'active')->exists()
            && $this->db->table('zp_user')->where('id', $actorId)->where('status', 'a')->exists();
    }

    public function actorLocale(int $actorId): string
    {
        $settings = $this->db->table('zp_user')->where('id', $actorId)->value('settings');
        $decoded = is_string($settings) && $settings !== '' ? safe_unserialize($settings, []) : [];
        $locale = is_array($decoded) ? ($decoded['language'] ?? null) : null;

        return in_array($locale, ['en-US', 'fr-CH'], true) ? $locale : 'fr-CH';
    }

    public function assertScope(int $actorId, ?int $projectId): void
    {
        if (! $this->activeActor($actorId)
            || ($projectId !== null && ! $this->projects->userCanAccessProject($actorId, $projectId))) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string,mixed> */
    public function createConversation(int $actorId, ?int $projectId, ?string $pagePath): array
    {
        $this->assertScope($actorId, $projectId);
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_agent_conversations')->insertGetId([
            'owner_user_id' => $actorId,
            'project_id' => $projectId,
            'title' => 'New conversation',
            'state' => 'idle',
            'page_path' => $pagePath,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->conversation($id, $actorId);
    }

    /** @return array<string,mixed> */
    public function conversation(int $id, int $actorId, bool $lock = false): array
    {
        $query = $this->db->table('julianna_agent_conversations')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($row === null) {
            throw new NotFoundException;
        }
        if ((int) $row->owner_user_id !== $actorId) {
            throw new AuthorizationException;
        }
        $this->assertScope($actorId, $row->project_id === null ? null : (int) $row->project_id);

        return (array) $row;
    }

    /** @return list<array<string,mixed>> */
    public function conversations(int $actorId, ?int $projectId = null): array
    {
        $this->assertScope($actorId, $projectId);
        $query = $this->db->table('julianna_agent_conversations')->where('owner_user_id', $actorId);
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query->orderByDesc('id')->limit(100)->get()->map(
            fn ($row): array => (array) $row
        )->filter(fn (array $row): bool => $row['project_id'] === null
            || $this->projects->userCanAccessProject($actorId, (int) $row['project_id']))->values()->all();
    }

    /** @return list<array<string,mixed>> */
    public function turns(int $conversationId): array
    {
        $newest = $this->db->table('julianna_agent_turns')->where('conversation_id', $conversationId)
            ->orderByDesc('id')->limit(300)->get()->map(static fn ($row): array => (array) $row)->all();

        return array_reverse($newest);
    }

    /** @return array<string,mixed>|null */
    public function turnByClientKey(int $conversationId, string $key): ?array
    {
        $row = $this->db->table('julianna_agent_turns')->where('conversation_id', $conversationId)
            ->where('client_key', $key)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string,mixed> */
    public function addTurn(int $conversationId, string $role, string $content, ?array $metadata = null, ?string $clientKey = null): array
    {
        if (! in_array($role, ['user', 'assistant', 'tool'], true) || strlen($content) > 100000) {
            throw new InvalidArgumentException('Invalid agent turn.');
        }
        $id = (int) $this->db->table('julianna_agent_turns')->insertGetId([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'client_key' => $clientKey,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->table('julianna_agent_conversations')->where('id', $conversationId)
            ->update(['updated_at' => gmdate('Y-m-d H:i:s')]);
        $row = $this->db->table('julianna_agent_turns')->where('id', $id)->first();

        return (array) $row;
    }

    public function setConversationState(int $conversationId, string $state, ?string $title = null): void
    {
        if (! in_array($state, ['idle', 'running'], true)) {
            throw new InvalidArgumentException('Invalid conversation state.');
        }
        $values = ['state' => $state, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($title !== null) {
            $values['title'] = mb_substr(trim($title), 0, 255);
        }
        $this->db->table('julianna_agent_conversations')->where('id', $conversationId)->update($values);
    }

    /** A pending receipt prevents re-execution after a worker/request crash. */
    public function createReceipt(
        int $conversationId,
        int $actorId,
        ?int $projectId,
        string $callId,
        string $toolName,
        string $argumentsHash,
        string $effect,
    ): bool {
        return $this->db->table('julianna_agent_tool_receipts')->insertOrIgnore([
            'conversation_id' => $conversationId,
            'actor_user_id' => $actorId,
            'project_id' => $projectId,
            'tool_call_id' => $callId,
            'tool_name' => $toolName,
            'arguments_hash' => $argumentsHash,
            'effect' => $effect,
            'status' => 'pending',
            'result_json' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
        ]) === 1;
    }

    /** @return array<string,mixed>|null */
    public function receipt(int $conversationId, string $callId): ?array
    {
        $row = $this->db->table('julianna_agent_tool_receipts')
            ->where('conversation_id', $conversationId)->where('tool_call_id', $callId)->first();

        return $row === null ? null : (array) $row;
    }

    /** @param array<string,mixed> $result */
    public function finishReceipt(int $conversationId, string $callId, array $result): void
    {
        $this->db->table('julianna_agent_tool_receipts')
            ->where('conversation_id', $conversationId)->where('tool_call_id', $callId)
            ->where('status', 'pending')->update([
                'status' => ($result['ok'] ?? false) ? 'completed' : 'failed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * A project-level action claim is independent of model call IDs and run IDs.
     * Once claimed, an uncertain write is never replayed by another background
     * review. A PM can still issue a new explicit interactive request.
     *
     * @return array{created:bool,status:string}
     */
    public function claimBackgroundAction(int $projectId, int $actorId, string $toolName, string $key): array
    {
        $created = $this->db->table('julianna_agent_action_claims')->insertOrIgnore([
            'project_id' => $projectId,
            'actor_user_id' => $actorId,
            'tool_name' => $toolName,
            'action_key' => $key,
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
        ]) === 1;
        $row = $this->db->table('julianna_agent_action_claims')->where('action_key', $key)->first();
        if ($row === null || (int) $row->project_id !== $projectId || (string) $row->tool_name !== $toolName) {
            throw new \RuntimeException('Background action claim could not be loaded.');
        }

        return ['created' => $created, 'status' => (string) $row->status];
    }

    public function finishBackgroundAction(string $key, bool $success): void
    {
        $this->db->table('julianna_agent_action_claims')->where('action_key', $key)
            ->where('status', 'pending')->update([
                'status' => $success ? 'completed' : 'failed',
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function createDraft(int $conversationId, int $projectId, int $actorId, string $toolName, array $arguments, string $reason): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->db->table('julianna_agent_drafts')->insertGetId([
            'conversation_id' => $conversationId,
            'project_id' => $projectId,
            'actor_user_id' => $actorId,
            'tool_name' => $toolName,
            'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
            'reason' => mb_substr($reason, 0, 2000),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->draft($id);
    }

    /** @return array<string,mixed> */
    public function draft(int $id): array
    {
        $row = $this->db->table('julianna_agent_drafts')->where('id', $id)->first();
        if ($row === null) {
            throw new NotFoundException;
        }

        return (array) $row;
    }

    /** @return list<array<string,mixed>> */
    public function drafts(int $projectId): array
    {
        return $this->db->table('julianna_agent_drafts')->where('project_id', $projectId)
            ->whereIn('status', ['draft', 'publishing', 'failed'])->orderByDesc('id')->limit(100)
            ->get()->map(static fn ($row): array => (array) $row)->all();
    }

    public function setDraftStatus(int $id, string $currentStatus, string $newStatus): bool
    {
        if (! in_array($newStatus, ['publishing', 'published', 'failed', 'discarded'], true)) {
            throw new InvalidArgumentException('Invalid draft state.');
        }

        return $this->db->table('julianna_agent_drafts')->where('id', $id)
            ->where('status', $currentStatus)->update([
                'status' => $newStatus, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function questions(?int $projectId, int $actorId): array
    {
        $query = $this->db->table('julianna_agent_questions as questions')
            ->join('julianna_agent_conversations as conversations', 'conversations.id', '=', 'questions.conversation_id')
            ->where('conversations.owner_user_id', $actorId)
            ->where('questions.status', 'open');
        if ($projectId === null) {
            $query->whereNull('questions.project_id');
        } else {
            $query->where('questions.project_id', $projectId);
        }

        return $query->orderByDesc('questions.id')->limit(30)
            ->get(['questions.*'])->map(static fn ($row): array => (array) $row)->all();
    }

    public function addQuestion(int $conversationId, ?int $projectId, string $question): void
    {
        $this->db->table('julianna_agent_questions')->insert([
            'conversation_id' => $conversationId,
            'project_id' => $projectId,
            'question' => $question,
            'status' => 'open',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'resolved_at' => null,
        ]);
    }

    public function resolveQuestions(int $conversationId): void
    {
        $this->db->table('julianna_agent_questions')->where('conversation_id', $conversationId)
            ->where('status', 'open')->update([
                'status' => 'answered', 'resolved_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
