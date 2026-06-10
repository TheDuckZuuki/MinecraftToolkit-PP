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
                    ? 'Wings ist nicht erreichbar oder der Server fehlt auf dem Node.'
                    : 'Der Server muss gestoppt sein, bevor diese Aktion fortgesetzt werden kann.'
            );
        }
    }
}
