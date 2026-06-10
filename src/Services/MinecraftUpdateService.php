<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitUpdateCheck;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftUpdateService
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly GeyserDownloadService $geyser,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftPackageInstaller $installer,
    ) {}

    /** @return array<int, MinecraftToolkitUpdateCheck> */
    public function checkAll(Server $server, MinecraftToolkitSetup $setup): array
    {
        $checks = [];
        foreach ($this->managedPackages($server) as $package) {
            try {
                $candidate = $this->candidate($package, $setup);
                $status = $candidate['version_id'] === $package->source_version_id
                    ? 'up_to_date'
                    : 'update_available';
                $message = $status === 'up_to_date'
                    ? 'Das Paket ist aktuell.'
                    : "Version {$candidate['version_number']} ist verfügbar.";
                $checks[] = $this->storeCheck($package, $status, $message, $candidate);
            } catch (MinecraftToolkitException $exception) {
                $checks[] = $this->storeCheck($package, 'error', $exception->getMessage());
            } catch (\Throwable $exception) {
                report($exception);
                $checks[] = $this->storeCheck(
                    $package,
                    'error',
                    'Die Updateprüfung ist technisch fehlgeschlagen.'
                );
            }
        }

        $this->log($server, 'updates_checked', 'info', sprintf(
            '%d verwaltete Pakete wurden auf Updates geprüft.',
            count($checks)
        ));

        return $checks;
    }

    public function updatePackage(Server $server, MinecraftToolkitSetup $setup, int $packageId): MinecraftToolkitPackage
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (!$package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException('Das verwaltete Paket wurde nicht gefunden.');
        }

        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.update.{$server->uuid}", 600);
        if (!$lock->get()) {
            throw new MinecraftToolkitException('Für diesen Server läuft bereits ein Paketupdate.');
        }

        $candidate = null;
        try {
            $this->state->assertOffline($server);
            $candidate = $this->candidate($package, $setup);
            if ($candidate['version_id'] === $package->source_version_id) {
                $this->storeCheck($package, 'up_to_date', 'Das Paket ist bereits aktuell.', $candidate);

                return $package;
            }

            $oldPath = $package->file_path;
            $newPath = dirname($oldPath) . '/' . $candidate['file_name'];
            if ($newPath !== $oldPath && $this->files->exists($server, $newPath)) {
                throw new MinecraftToolkitException(
                    "Die neue Paketdatei {$candidate['file_name']} existiert bereits."
                );
            }
            $backup = $this->files->backupIfPresent($server, $oldPath);

            try {
                $this->files->downloadJar(
                    $server,
                    $candidate['url'],
                    $newPath,
                    $candidate['hashes']
                );
            } catch (\Throwable $exception) {
                if ($backup !== null) {
                    try {
                        if ($this->files->exists($server, $newPath)) {
                            $this->files->delete($server, $newPath);
                        }
                        $this->files->move($server, $backup, $oldPath);
                    } catch (\Throwable $restoreException) {
                        Log::error('Minecraft Toolkit could not restore a package backup.', [
                            'server_uuid' => $server->uuid,
                            'package_id' => $package->id,
                            'exception' => $restoreException,
                        ]);
                    }
                }

                throw $exception;
            }

            $oldVersionId = $package->source_version_id;
            $oldVersionNumber = $package->version_number;
            $package->forceFill([
                'source_version_id' => $candidate['version_id'],
                'version_number' => $candidate['version_number'],
                'file_name' => $candidate['file_name'],
                'file_path' => $newPath,
                'download_url' => $candidate['url'],
                'sha1' => $candidate['hashes']['sha1'] ?? null,
                'sha512' => $candidate['hashes']['sha512'] ?? null,
                'dependencies_json' => $candidate['dependencies'],
                'minecraft_version' => $setup->minecraft_version,
                'loader' => $setup->loader ?? $setup->software,
                'installed_at' => now(),
                'last_checked_at' => now(),
            ])->save();

            MinecraftToolkitUpdateCheck::query()->create([
                'server_uuid' => $package->server_uuid,
                'package_id' => $package->id,
                'old_version_id' => $oldVersionId,
                'new_version_id' => $candidate['version_id'],
                'old_version_number' => $oldVersionNumber,
                'new_version_number' => $candidate['version_number'],
                'status' => 'up_to_date',
                'message' => 'Das Paket wurde erfolgreich aktualisiert.',
                'checked_at' => now(),
            ]);
            $this->log(
                $server,
                'package_updated',
                'success',
                "{$package->project_name} wurde auf {$candidate['version_number']} aktualisiert.",
                ['package_id' => $package->id, 'backup' => $backup]
            );

            return $package->refresh();
        } catch (MinecraftToolkitException $exception) {
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $exception->getMessage());
            }
            $this->log($server, 'package_update_failed', 'error', $exception->getMessage(), [
                'package_id' => $package->id,
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $message = 'Das Paketupdate ist fehlgeschlagen. Die alte Datei wurde soweit möglich wiederhergestellt.';
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $message);
            }
            $this->log($server, 'package_update_failed', 'error', $message, ['package_id' => $package->id]);
            throw new MinecraftToolkitException($message, previous: $exception);
        } finally {
            $lock->release();
        }
    }

    /** @return array{updated: int, failed: int, errors: string[]} */
    public function updateAll(Server $server, MinecraftToolkitSetup $setup): array
    {
        $this->state->assertOffline($server);
        $this->checkAll($server, $setup);
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->managedPackages($server) as $packageRecord) {
            $check = MinecraftToolkitUpdateCheck::query()
                ->where('server_uuid', $server->uuid)
                ->where('package_id', $packageRecord->id)
                ->latest('id')
                ->first();
            if (!$check instanceof MinecraftToolkitUpdateCheck || $check->status !== 'update_available') {
                continue;
            }

            try {
                $before = $packageRecord->source_version_id;
                $package = $this->updatePackage($server, $setup, (int) $check->package_id);
                if ($package->source_version_id !== $before) {
                    $updated++;
                }
            } catch (MinecraftToolkitException $exception) {
                $failed++;
                $errors[] = $exception->getMessage();
            }
        }

        return compact('updated', 'failed', 'errors');
    }

    /** @return array<string, mixed> */
    public function candidate(MinecraftToolkitPackage $package, MinecraftToolkitSetup $setup): array
    {
        if ($package->source === 'modrinth') {
            $candidate = $this->modrinth->updateCandidate($package->source_project_id, $setup);

            return $this->normalizeModrinthCandidate($candidate);
        }

        if ($package->source === 'curseforge') {
            $candidate = $this->curseForge->updateCandidate($package->source_project_id, $setup);

            return $this->normalizeModrinthCandidate($candidate);
        }

        if ($package->source === 'geysermc'
            && in_array($package->source_project_id, ['geyser', 'floodgate'], true)) {
            $download = $this->geyser->latestSpigot($package->source_project_id);

            return $this->normalizeGeyserCandidate($download);
        }

        throw new MinecraftToolkitException('Für diese Paketquelle ist keine automatische Updateprüfung verfügbar.');
    }

    /** @param array<string, mixed> $candidate
     *  @return array<string, mixed>
     */
    public function normalizeModrinthCandidate(array $candidate): array
    {
        $version = $candidate['version'] ?? null;
        $file = is_array($version) ? ($version['selected_file'] ?? null) : null;
        if (!is_array($version) || !is_array($file)) {
            throw new MinecraftToolkitException('Die Modrinth-Updateinformationen sind unvollständig.');
        }

        return [
            'version_id' => (string) $version['id'],
            'version_number' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
            'file_name' => $this->installer->safeFileName((string) $file['filename']),
            'url' => (string) $file['url'],
            'hashes' => is_array($file['hashes'] ?? null) ? $file['hashes'] : [],
            'dependencies' => is_array($candidate['dependencies'] ?? null) ? $candidate['dependencies'] : [],
        ];
    }

    /** @param array<string, mixed> $download
     *  @return array<string, mixed>
     */
    public function normalizeGeyserCandidate(array $download): array
    {
        return [
            'version_id' => (string) $download['build'],
            'version_number' => $download['version'] . '+' . $download['build'],
            'file_name' => $this->installer->safeFileName((string) $download['file_name']),
            'url' => (string) $download['url'],
            'hashes' => ['sha256' => (string) $download['sha256']],
            'dependencies' => ['sha256' => (string) $download['sha256']],
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MinecraftToolkitPackage> */
    private function managedPackages(Server $server)
    {
        return MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->whereIn('package_type', ['plugin', 'mod', 'crossplay', 'dependency'])
            ->orderBy('project_name')
            ->get();
    }

    /** @param array<string, mixed>|null $candidate */
    private function storeCheck(
        MinecraftToolkitPackage $package,
        string $status,
        string $message,
        ?array $candidate = null
    ): MinecraftToolkitUpdateCheck {
        $package->forceFill(['last_checked_at' => now()])->save();

        return MinecraftToolkitUpdateCheck::query()->create([
            'server_uuid' => $package->server_uuid,
            'package_id' => $package->id,
            'old_version_id' => $package->source_version_id,
            'new_version_id' => $candidate['version_id'] ?? null,
            'old_version_number' => $package->version_number,
            'new_version_number' => $candidate['version_number'] ?? null,
            'status' => $status,
            'message' => $message,
            'checked_at' => now(),
        ]);
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
            Log::warning('Minecraft Toolkit could not persist an update log.', ['exception' => $exception]);
        }
    }
}
