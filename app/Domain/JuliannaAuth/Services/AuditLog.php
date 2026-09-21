<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use Leantime\Domain\JuliannaAuth\Enums\AuditEvent;
use Leantime\Domain\JuliannaAuth\Repositories\AuditRepository;

class AuditLog
{
    public function __construct(
        private AuditRepository $repository,
        private AuthClock $clock,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        AuditEvent $event,
        ?int $accountId = null,
        ?int $actorUserId = null,
        ?string $subjectIdentifier = null,
        ?string $ipAddress = null,
        array $context = []
    ): void {
        $this->repository->record(
            event: $event,
            now: $this->clock->databaseNow(),
            accountId: $accountId,
            actorUserId: $actorUserId,
            subjectIdentifierHash: $subjectIdentifier === null ? null : hash('sha256', $subjectIdentifier),
            ipHash: $ipAddress === null ? null : hash('sha256', trim($ipAddress)),
            context: $context,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(int $accountId): array
    {
        return $this->repository->forAccount($accountId);
    }
}
