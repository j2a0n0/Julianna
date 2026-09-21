<?php

namespace Leantime\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\AuditEvent;

class AuditRepository
{
    public function __construct(
        private ConnectionInterface $connection
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        AuditEvent $event,
        string $now,
        ?int $accountId = null,
        ?int $actorUserId = null,
        ?string $subjectIdentifierHash = null,
        ?string $ipHash = null,
        array $context = []
    ): void {
        $this->connection->table('julianna_auth_audit_events')->insert([
            'account_id' => $accountId,
            'actor_user_id' => $actorUserId,
            'event' => $event->value,
            'subject_identifier_hash' => $subjectIdentifierHash,
            'ip_hash' => $ipHash,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(int $accountId): array
    {
        return $this->connection->table('julianna_auth_audit_events')
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
