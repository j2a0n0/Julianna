<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Permissions;

use Leantime\Core\Auth\Permissions\Permission;
use Leantime\Core\Auth\Permissions\ProvidesPermissions;

final class WhiteboardsPermissions implements ProvidesPermissions
{
    public const VIEW = 'whiteboards.view';

    public const CREATE = 'whiteboards.create';

    public const EDIT = 'whiteboards.edit';

    public function domain(): string
    {
        return 'whiteboards';
    }

    public function permissions(): array
    {
        return [
            new Permission(self::VIEW, 'View project whiteboards', true),
            new Permission(self::CREATE, 'Create project whiteboards', true),
            new Permission(self::EDIT, 'Edit project whiteboards', true),
        ];
    }
}
