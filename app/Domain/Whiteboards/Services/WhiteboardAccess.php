<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Services;

use Illuminate\Database\ConnectionInterface;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\Auth\Models\Roles;

/** Live, actor-aware authorization shared by web, MCP, and queued callers. */
final class WhiteboardAccess
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PermissionService $permissions,
    ) {}

    public function authorize(int $projectId, int $actorId, string $permission): void
    {
        if ($projectId <= 0 || $actorId <= 0) {
            throw new AuthorizationException;
        }

        // A web/API session must never be able to claim a different actor ID.
        $sessionActorId = session('userdata.id');
        if ($sessionActorId !== null && (int) $sessionActorId !== $actorId) {
            throw new AuthorizationException;
        }

        $project = $this->db->table('zp_projects')->where('id', $projectId)->first();
        if ($project === null || (string) ($project->state ?? '') === '-1') {
            throw new NotFoundException;
        }
        $user = $this->db->table('zp_user')->where('id', $actorId)->first();
        $account = $this->db->table('julianna_auth_accounts')->where('user_id', $actorId)->first();
        if ($user === null || $account === null
            || strtolower((string) ($user->status ?? '')) !== 'a'
            || (string) ($account->state ?? '') !== 'active') {
            throw new AuthorizationException;
        }

        $globalRole = $this->roleName((string) ($user->role ?? ''));
        $isAdmin = in_array($globalRole, [Roles::$admin, Roles::$owner], true);
        if (! $isAdmin && ! $this->hasProjectAccess($project, $user, $actorId, $projectId)) {
            throw new AuthorizationException;
        }

        $effectiveRole = $globalRole;
        if (! in_array($globalRole, [Roles::$manager, Roles::$admin, Roles::$owner], true)) {
            $relation = $this->db->table('zp_relationuserproject')
                ->where('projectId', $projectId)->where('userId', $actorId)->first();
            $projectRole = $this->roleName((string) ($relation->projectRole ?? ''));
            if ($projectRole !== null) {
                $effectiveRole = $projectRole;
            }
        }
        if ($effectiveRole === null || ! $this->permissions->roleHasPermission($effectiveRole, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function hasProjectAccess(object $project, object $user, int $actorId, int $projectId): bool
    {
        if ((string) ($project->psettings ?? '') === 'all') {
            return true;
        }
        if ((string) ($project->psettings ?? '') === 'clients'
            && (int) ($project->clientId ?? -1) === (int) ($user->clientId ?? -2)) {
            return true;
        }

        return $this->db->table('zp_relationuserproject')
            ->where('userId', $actorId)->where('projectId', $projectId)->exists();
    }

    private function roleName(string $role): ?string
    {
        if ($role === '') {
            return null;
        }
        if (ctype_digit($role)) {
            $resolved = Roles::getRoleString((int) $role);

            return $resolved === false ? null : (string) $resolved;
        }

        return in_array($role, Roles::getRoles(), true) ? $role : null;
    }
}
