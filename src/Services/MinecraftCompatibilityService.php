<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;

class MinecraftCompatibilityService
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly GeyserDownloadService $geyser,
        private readonly MinecraftSoftwareService $software,
    ) {}

    /**
     * @return array{
     *   target: array<string, mixed>,
     *   packages: array<int, array<string, mixed>>,
     *   blocking: int,
     *   updates: int
     * }
     */
    public function check(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): array {
        if (!array_key_exists($minecraftVersion, $this->software->versionOptions($setup->software))) {
            throw new MinecraftToolkitException('The selected version of Minecraft is not available.');
        }
        if (in_array($setup->software, ['fabric', 'forge', 'neoforge'], true)
            && (!is_string($loaderVersion)
                || !array_key_exists(
                    $loaderVersion,
                    $this->software->loaderVersionOptions($setup->software, $minecraftVersion)
                ))) {
            throw new MinecraftToolkitException('Select a valid loader version for the target.');
        }

        $target = $this->targetSetup($setup, $minecraftVersion, $loaderVersion);
        $packages = MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('enabled', true)
            ->whereIn('package_type', ['plugin', 'mod', 'dependency', 'crossplay'])
            ->orderBy('project_name')
            ->get()
            ->map(fn (MinecraftToolkitPackage $package): array => $this->checkPackage($package, $target))
            ->all();

        return [
            'target' => [
                'minecraft_version' => $target->minecraft_version,
                'loader' => $target->loader,
                'loader_version' => $target->loader_version,
            ],
            'packages' => $packages,
            'blocking' => collect($packages)->whereIn('status', ['incompatible', 'unknown', 'pinned'])->count(),
            'updates' => collect($packages)->whereIn('status', ['update_required', 'system_update'])->count(),
        ];
    }

    public function targetSetup(
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): MinecraftToolkitSetup {
        $target = $setup->replicate();
        $target->forceFill([
            'minecraft_version' => $minecraftVersion,
            'loader_version' => in_array($setup->software, ['fabric', 'forge', 'neoforge'], true)
                ? $loaderVersion
                : null,
        ]);

        return $target;
    }

    /** @return array<string, mixed> */
    public function checkPackage(MinecraftToolkitPackage $package, MinecraftToolkitSetup $target): array
    {
        $base = [
            'id' => $package->id,
            'name' => $package->project_name,
            'source' => $package->source,
            'type' => $package->package_type,
            'current_version' => $package->version_number,
            'target_version' => null,
            'system' => $package->is_system_package,
            'pinned' => (bool) $package->update_pinned,
        ];

        if (in_array($package->source, ['modrinth', 'curseforge'], true)) {
            try {
                $candidate = $package->source === 'modrinth'
                    ? $this->modrinth->updateCandidate($package->source_project_id, $target)
                    : $this->curseForge->updateCandidate($package->source_project_id, $target);
                $version = $candidate['version'];
                $sameVersion = (string) $version['id'] === (string) $package->source_version_id;
                if (!$sameVersion && $package->update_pinned) {
                    return $base + [
                        'status' => 'pinned',
                        'target_version' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
                        'message' => 'The package is pinned and will not be automatically updated when the version changes.',
                    ];
                }

                return $base + [
                    'status' => $sameVersion ? 'compatible' : 'update_required',
                    'target_version' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
                    'message' => $sameVersion
                        ? 'The installed version supports the target.'
                        : 'A compatible package version is available and will be updated.',
                ];
            } catch (MinecraftToolkitException $exception) {
                $unknown = str_contains($exception->getMessage(), 'unavailable')
                    || str_contains($exception->getMessage(), 'disabled');

                return $base + [
                    'status' => $unknown ? 'unknown' : 'incompatible',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($package->source === 'geysermc'
            && in_array($package->source_project_id, ['geyser', 'floodgate'], true)) {
            try {
                $download = $this->geyser->latestSpigot($package->source_project_id);
                $sameBuild = (string) $download['build'] === (string) $package->source_version_id;

                return $base + [
                    'status' => $sameBuild
                        ? 'compatible'
                        : ($package->update_pinned ? 'pinned' : 'system_update'),
                    'target_version' => $download['version'] . '+' . $download['build'],
                    'message' => 'The cross-play system package will be retained for the target server.',
                ];
            } catch (MinecraftToolkitException $exception) {
                return $base + [
                    'status' => 'unknown',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $base + [
            'status' => 'unknown',
            'message' => 'No reliable compatibility data is available for this package source.',
        ];
    }
}
