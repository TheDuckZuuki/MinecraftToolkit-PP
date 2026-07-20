<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;

class MinecraftServerStateService
{
    public function assertOffline(Server $server): void
    {
        $state = app(DaemonServerRepository::class)
            ->setServer($server)
            ->getDetails()['state'] ?? 'missing';

        if (!in_array($state, ['offline', 'exited', 'dead', 'created'], true)) {
            throw new MinecraftToolkitException(
                $state === 'missing'
                    ? 'Wings is unreachable, or the server is missing from the node.'
                    : 'The server must be stopped before this action can continue.'
            );
        }
    }
}
