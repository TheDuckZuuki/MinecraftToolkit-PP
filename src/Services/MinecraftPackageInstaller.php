<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftPackageInstaller
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
    ) {}

    public function installModrinthPlugin(Server $server, MinecraftToolkitSetup $setup, string $projectId): MinecraftToolkitPackage
    {
        return $this->installModrinthPackage($server, $setup, $projectId);
    }

    public function installModrinthPackage(Server $server, MinecraftToolkitSetup $setup, string $projectId): MinecraftToolkitPackage
    {
        return $this->installPackage($server, $setup, 'modrinth', $projectId);
    }

    public function installCurseForgePackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $projectId
    ): MinecraftToolkitPackage {
        return $this->installPackage($server, $setup, 'curseforge', $projectId);
    }

    private function installPackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        string $projectId
    ): MinecraftToolkitPackage {
        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.install.{$server->uuid}", 600);
        if (!$lock->get()) {
            throw new MinecraftToolkitException('Für diesen Server läuft bereits eine Paketinstallation.');
        }

        try {
            $this->state->assertOffline($server);

            return $this->performInstall($server, $setup, $source, $projectId, false, []);
        } finally {
            $lock->release();
        }
    }

    /** @param string[] $stack */
    private function performInstall(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        string $projectId,
        bool $requiredDependency,
        array $stack
    ): MinecraftToolkitPackage {
        $packageType = match ($setup->software) {
            'paper', 'purpur', 'folia' => 'plugin',
            'fabric', 'forge', 'neoforge' => 'mod',
            default => throw new MinecraftToolkitException(
                'Diese Serversoftware unterstützt keine Plugin- oder Mod-Installation.'
            ),
        };
        $targetDirectory = $packageType === 'plugin' ? 'plugins' : 'mods';
        $packageLabel = $packageType === 'plugin' ? 'Plugin' : 'Mod';

        if ($packageType === 'mod' && $setup->loader_version === null) {
            throw new MinecraftToolkitException('Für diesen Mod-Server ist keine Loader-Version gespeichert.');
        }

        $alreadyInstalled = MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('source', $source)
            ->where('source_project_id', $projectId)
            ->where('managed', true)
            ->first();
        if ($alreadyInstalled instanceof MinecraftToolkitPackage) {
            if ($requiredDependency) {
                return $alreadyInstalled;
            }

            throw new MinecraftToolkitException("Dieses $packageLabel wird bereits von Minecraft Toolkit verwaltet.");
        }

        $stackKey = "$source:$projectId";
        if (in_array($stackKey, $stack, true)) {
            throw new MinecraftToolkitException('Eine Paketabhängigkeit verweist rekursiv auf sich selbst.');
        }
        $stack[] = $stackKey;

        try {
            $candidate = match ($source) {
                'modrinth' => $this->modrinth->installationCandidate($projectId, $setup),
                'curseforge' => $this->curseForge->installationCandidate($projectId, $setup),
                default => throw new MinecraftToolkitException('Die Paketquelle wird nicht unterstützt.'),
            };

            foreach ($this->requiredDependencies($candidate['dependencies'] ?? []) as $dependency) {
                $dependencyProjectId = $dependency['project_id'] ?? null;
                if (!is_string($dependencyProjectId) || $dependencyProjectId === '') {
                    throw new MinecraftToolkitException(
                        "Eine Pflicht-Abhängigkeit für $projectId konnte nicht eindeutig aufgelöst werden."
                    );
                }

                $this->performInstall($server, $setup, $source, $dependencyProjectId, true, $stack);
            }

            $project = $candidate['project'];
            $version = $candidate['version'];
            $file = $version['selected_file'];
            $fileName = $this->safeFileName((string) $file['filename']);
            $path = "/$targetDirectory/$fileName";

            if ($this->files->exists($server, $path)) {
                throw new MinecraftToolkitException("Die Datei $fileName existiert bereits im $targetDirectory-Ordner.");
            }

            $metadata = $this->files->downloadJarWithMetadata(
                $server,
                (string) $file['url'],
                $path,
                is_array($file['hashes'] ?? null) ? $file['hashes'] : []
            );
            $this->assertDownloadedVersionMatchesCandidate($metadata['plugin_version'] ?? null, (string) ($version['version_number'] ?? $version['name'] ?? ''));

            $package = MinecraftToolkitPackage::query()->create([
                'server_uuid' => $server->uuid,
                'setup_id' => $setup->id,
                'source' => $source,
                'source_project_id' => $project['project_id'],
                'source_project_slug' => $project['slug'],
                'source_version_id' => $version['id'],
                'project_name' => $project['title'],
                'project_type' => $packageType,
                'package_type' => $requiredDependency ? 'dependency' : $packageType,
                'loader' => $setup->software,
                'minecraft_version' => $setup->minecraft_version,
                'version_number' => $version['version_number'] ?? $version['name'] ?? null,
                'file_name' => $fileName,
                'file_path' => $path,
                'download_url' => $file['url'],
                'sha1' => $metadata['sha1'] ?? ($file['hashes']['sha1'] ?? null),
                'sha512' => $metadata['sha512'] ?? ($file['hashes']['sha512'] ?? null),
                'side' => $project['server_side'],
                'dependencies_json' => $candidate['dependencies'],
                'is_required_dependency' => $requiredDependency,
                'is_system_package' => false,
                'managed' => true,
                'enabled' => true,
                'installed_by' => user()?->id,
                'installed_at' => now(),
            ]);

            $this->log($server, 'package_installed', 'success', "$packageLabel {$project['title']} wurde installiert.", [
                'project_id' => $project['project_id'],
                'version_id' => $version['id'],
                'file' => $fileName,
                'required_dependency' => $requiredDependency,
            ]);

            return $package;
        } catch (\Throwable $exception) {
            $message = $exception instanceof MinecraftToolkitException
                ? $exception->getMessage()
                : "Das $packageLabel konnte nicht installiert werden. Technische Details wurden protokolliert.";
            $this->log($server, 'package_install_failed', 'error', $message, ['project_id' => $projectId]);
            Log::error('Minecraft Toolkit package installation failed', [
                'server_uuid' => $server->uuid,
                'project_id' => $projectId,
                'source' => $source,
                'exception' => $exception,
            ]);

            throw new MinecraftToolkitException($message, previous: $exception);
        }
    }


    private function assertDownloadedVersionMatchesCandidate(?string $downloadedVersion, string $expectedVersion): void
    {
        $downloadedVersion = trim((string) $downloadedVersion);
        $expectedVersion = trim($expectedVersion);
        if ($downloadedVersion === '' || $expectedVersion === '') {
            return;
        }

        if ($this->versionBase($downloadedVersion) !== $this->versionBase($expectedVersion)) {
            throw new MinecraftToolkitException(
                "Die heruntergeladene Datei enthält Version $downloadedVersion, erwartet wurde aber $expectedVersion. Die Installation wurde abgebrochen, weil die Quelle eine falsche/alte Datei geliefert hat."
            );
        }
    }

    private function versionBase(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/^v/i', '', $version) ?? $version;
        $version = preg_replace('/\+.*$/', '', $version) ?? $version;
        $version = preg_replace('/-SNAPSHOT.*$/', '', $version) ?? $version;
        $version = preg_replace('/^(?:bukkit|spigot|paper|fabric|forge|neoforge)-/i', '', $version) ?? $version;

        return trim($version);
    }

    /** @param mixed $dependencies
     *  @return array<int, array<string, mixed>>
     */
    private function requiredDependencies(mixed $dependencies): array
    {
        if (!is_array($dependencies)) {
            return [];
        }

        return collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && is_string($dependency['project_id'] ?? null))
            ->values()
            ->all();
    }

    public function safeFileName(string $fileName): string
    {
        if (str_contains($fileName, '..')
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')) {
            throw new MinecraftToolkitException('Der Paketdateiname ist ungültig.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+() -]{0,199}\.jar$/i', $fileName)) {
            throw new MinecraftToolkitException('Der Paketdateiname ist ungültig.');
        }

        return $fileName;
    }

    /** @param array<string, mixed> $context */
    private function log(Server $server, string $action, string $level, string $message, array $context = []): void
    {
        try {
            MinecraftToolkitLog::query()->create([
                'server_uuid' => $server->uuid,
                'user_id' => user()?->id,
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context_json' => $context ?: null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit could not persist a package log.', ['exception' => $exception]);
        }
    }
}
