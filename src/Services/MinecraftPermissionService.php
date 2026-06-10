<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;

class MinecraftPermissionService
{
    public function canView(User $user, Server $server): bool
    {
        return $user->isRootAdmin()
            || $server->owner_id === $user->id
            || $user->can('view server', $server);
    }

    public function canModify(User $user, Server $server): bool
    {
        if ((bool) config('minecrafttoolkit.admins_only', false) && !$user->isRootAdmin()) {
            return false;
        }

        return $user->isRootAdmin()
            || $server->owner_id === $user->id
            || (
                $user->can(SubuserPermission::FileCreate, $server)
                && $user->can(SubuserPermission::FileUpdate, $server)
            );
    }
}
