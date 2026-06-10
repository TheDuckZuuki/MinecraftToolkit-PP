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
    ): MinecraftToolkitPackage
    {
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

        if (MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('source', $source)
            ->where('source_project_id', $projectId)
            ->where('managed', true)
            ->exists()) {
            throw new MinecraftToolkitException("Dieses $packageLabel wird bereits von Minecraft Toolkit verwaltet.");
        }

        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.install.{$server->uuid}", 600);
        if (!$lock->get()) {
            throw new MinecraftToolkitException('Für diesen Server läuft bereits eine Paketinstallation.');
        }

        try {
            $this->state->assertOffline($server);
            $candidate = match ($source) {
                'modrinth' => $this->modrinth->installationCandidate($projectId, $setup),
                'curseforge' => $this->curseForge->installationCandidate($projectId, $setup),
                default => throw new MinecraftToolkitException('Die Paketquelle wird nicht unterstützt.'),
            };
            $project = $candidate['project'];
            $version = $candidate['version'];
            $file = $version['selected_file'];
            $fileName = $this->safeFileName((string) $file['filename']);
            $path = "/$targetDirectory/$fileName";

            if ($this->files->exists($server, $path)) {
                throw new MinecraftToolkitException("Die Datei $fileName existiert bereits im $targetDirectory-Ordner.");
            }

            $this->files->downloadJar(
                $server,
                (string) $file['url'],
                $path,
                is_array($file['hashes'] ?? null) ? $file['hashes'] : []
            );

            $package = MinecraftToolkitPackage::query()->create([
                'server_uuid' => $server->uuid,
                'setup_id' => $setup->id,
                'source' => $source,
                'source_project_id' => $project['project_id'],
                'source_project_slug' => $project['slug'],
                'source_version_id' => $version['id'],
                'project_name' => $project['title'],
                'project_type' => $packageType,
                'package_type' => $packageType,
                'loader' => $setup->software,
                'minecraft_version' => $setup->minecraft_version,
                'version_number' => $version['version_number'] ?? $version['name'] ?? null,
                'file_name' => $fileName,
                'file_path' => $path,
                'download_url' => $file['url'],
                'sha1' => $file['hashes']['sha1'] ?? null,
                'sha512' => $file['hashes']['sha512'] ?? null,
                'side' => $project['server_side'],
                'dependencies_json' => $candidate['dependencies'],
                'is_required_dependency' => false,
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
                'exception' => $exception,
            ]);

            throw new MinecraftToolkitException($message, previous: $exception);
        } finally {
            $lock->release();
        }
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
