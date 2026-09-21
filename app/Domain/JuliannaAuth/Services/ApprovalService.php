<?php

namespace Leantime\Domain\JuliannaAuth\Services;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Leantime\Domain\JuliannaAuth\Enums\AccountState;
use Leantime\Domain\JuliannaAuth\Enums\AuditEvent;
use Leantime\Domain\JuliannaAuth\Models\Account;
use Leantime\Domain\JuliannaAuth\Models\ApprovalResult;
use Leantime\Domain\JuliannaAuth\Repositories\AccountRepository;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;
use RuntimeException;

class ApprovalService
{
    private const MAX_SELF_REGISTERED_ROLE_LEVEL = 30;

    private const ADMIN_ROLE_LEVEL = 40;

    public function __construct(
        private ConnectionInterface $connection,
        private AccountRepository $accounts,
        private SessionRepository $sessions,
        private AuditLog $audit,
        private AuthClock $clock,
    ) {}

    /**
     * @param  array<int, int|string>  $projectIds
     */
    public function approve(int $accountId, string $role, array $projectIds, int $approverUserId): ApprovalResult
    {
        return $this->connection->transaction(function () use ($accountId, $role, $projectIds, $approverUserId): ApprovalResult {
            $this->assertAdministrator($approverUserId);
            $account = $this->accounts->lockRow($accountId)
                ?? throw new RuntimeException('Julianna account not found.');

            if ($account['state'] !== AccountState::PENDING_APPROVAL->value || $account['user_id'] !== null) {
                throw new DomainException('Only an unlinked account awaiting approval can be approved.');
            }

            $roleRow = $this->resolveAssignableRole($role);
            $projectIds = $this->validateProjectIds($projectIds);
            [$firstName, $lastName] = $this->splitName((string) $account['display_name']);
            $now = $this->clock->databaseNow();

            $userId = (int) $this->connection->table('zp_user')->insertGetId([
                'username' => $account['email_normalized'],
                'password' => '',
                'firstname' => $firstName,
                'lastname' => $lastName,
                'phone' => '',
                'profileId' => '',
                'status' => 'a',
                'role' => (string) $roleRow->level,
                'session' => '',
                'sessiontime' => '',
                'wage' => 0,
                'hours' => 0,
                'clientId' => 0,
                'notifications' => 1,
                'source' => 'julianna',
                'twoFAEnabled' => 0,
                'createdOn' => $now,
                'modified' => $now,
            ]);

            if ($projectIds !== []) {
                $relations = array_map(static fn (int $projectId): array => [
                    'userId' => $userId,
                    'projectId' => $projectId,
                    'wage' => 0,
                    'projectRole' => '',
                ], $projectIds);
                $this->connection->table('zp_relationuserproject')->insert($relations);
            }

            $this->accounts->update($accountId, [
                'user_id' => $userId,
                'state' => AccountState::ACTIVE->value,
                'approved_at' => $now,
                'approved_by_user_id' => $approverUserId,
                'rejected_at' => null,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                AuditEvent::APPROVED,
                accountId: $accountId,
                actorUserId: $approverUserId,
                context: [
                    'role' => (string) $roleRow->name,
                    'project_count' => count($projectIds),
                ],
            );

            $user = $this->connection->table('zp_user')->where('id', $userId)->first();

            return new ApprovalResult(
                account: $this->accounts->requireById($accountId),
                user: (array) $user,
            );
        });
    }

    public function reject(int $accountId, int $approverUserId): Account
    {
        return $this->connection->transaction(function () use ($accountId, $approverUserId): Account {
            $this->assertAdministrator($approverUserId);
            $account = $this->accounts->lockRow($accountId)
                ?? throw new RuntimeException('Julianna account not found.');

            if ($account['state'] !== AccountState::PENDING_APPROVAL->value || $account['user_id'] !== null) {
                throw new DomainException('Only an unlinked account awaiting approval can be rejected.');
            }

            $now = $this->clock->databaseNow();
            $this->accounts->update($accountId, [
                'state' => AccountState::REJECTED->value,
                'rejected_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->record(
                AuditEvent::REJECTED,
                accountId: $accountId,
                actorUserId: $approverUserId,
            );

            return $this->accounts->requireById($accountId);
        });
    }

    public function disable(int $accountId, int $actorUserId): Account
    {
        return $this->connection->transaction(function () use ($accountId, $actorUserId): Account {
            $this->assertAdministrator($actorUserId);
            $account = $this->accounts->lockRow($accountId)
                ?? throw new RuntimeException('Julianna account not found.');

            if ($account['state'] !== AccountState::ACTIVE->value) {
                throw new DomainException('Only an active account can be disabled.');
            }

            $now = $this->clock->databaseNow();
            $this->sessions->revokeAll($accountId, $now);
            $this->accounts->update($accountId, [
                'state' => AccountState::DISABLED->value,
                'disabled_at' => $now,
                'session_version' => ((int) $account['session_version']) + 1,
                'updated_at' => $now,
            ]);

            if ($account['user_id'] !== null) {
                $this->connection->table('zp_user')
                    ->where('id', (int) $account['user_id'])
                    ->update([
                        'status' => 'i',
                        'session' => '',
                        'sessiontime' => '',
                        'modified' => $now,
                    ]);
            }

            $this->audit->record(
                AuditEvent::ACCOUNT_DISABLED,
                accountId: $accountId,
                actorUserId: $actorUserId,
            );

            return $this->accounts->requireById($accountId);
        });
    }

    private function assertAdministrator(int $userId): void
    {
        $user = $this->connection->table('zp_user')
            ->select(['id', 'role', 'status'])
            ->where('id', $userId)
            ->first();

        if ($user === null || strtolower((string) $user->status) !== 'a' || (int) $user->role < self::ADMIN_ROLE_LEVEL) {
            throw new DomainException('An active Julianna administrator is required.');
        }
    }

    private function resolveAssignableRole(string $role): object
    {
        $query = $this->connection->table('zp_roles');
        $query = ctype_digit($role)
            ? $query->where('level', (int) $role)
            : $query->where('name', mb_strtolower(trim($role), 'UTF-8'));
        $roleRow = $query->first();

        if ($roleRow === null || (int) $roleRow->level > self::MAX_SELF_REGISTERED_ROLE_LEVEL) {
            throw new InvalidArgumentException('The selected role cannot be assigned to a public signup.');
        }

        return $roleRow;
    }

    /**
     * @param  array<int, int|string>  $projectIds
     * @return array<int, int>
     */
    private function validateProjectIds(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_map(static function (int|string $projectId): int {
            if (filter_var($projectId, FILTER_VALIDATE_INT) === false || (int) $projectId < 1) {
                throw new InvalidArgumentException('Project IDs must be positive integers.');
            }

            return (int) $projectId;
        }, $projectIds)));

        if ($projectIds === []) {
            return [];
        }

        $found = $this->connection->table('zp_projects')
            ->whereIn('id', $projectIds)
            ->count();

        if ($found !== count($projectIds)) {
            throw new InvalidArgumentException('One or more selected projects do not exist.');
        }

        return $projectIds;
    }

    /**
     * @return array{string, string}
     */
    private function splitName(string $displayName): array
    {
        $parts = preg_split('/\s+/u', trim($displayName), 2) ?: [];

        return [
            mb_substr($parts[0] ?? '', 0, 100, 'UTF-8'),
            mb_substr($parts[1] ?? '', 0, 100, 'UTF-8'),
        ];
    }
}
