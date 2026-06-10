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
use Illuminate\Support\Facades\Schema;

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
                $package = $this->syncPackageWithInstalledState($server, $package);
                if (($runtimeIssue = $this->runtimeIssue($server, $package)) !== null) {
                    $checks[] = $this->storeCheck($package, 'error', $runtimeIssue);
                    continue;
                }
                if (($dependencyIssue = $this->missingRequiredDependencyIssue($server, $package)) !== null) {
                    $checks[] = $this->storeCheck($package, 'error', $dependencyIssue);
                    continue;
                }

                $candidate = $this->candidate($package, $setup);
                $sameVersion = $candidate['version_id'] === $package->source_version_id
                    && $this->versionsEquivalent($candidate['version_number'], (string) $package->version_number);
                $status = $sameVersion ? 'up_to_date' : 'update_available';
                $message = $status === 'up_to_date'
                    ? 'Das Paket ist aktuell.'
                    : "Version {$candidate['version_number']} ist verfügbar oder die installierte Datei weicht von der Datenbank ab.";
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
            $package = $this->syncPackageWithInstalledState($server, $package);
            $candidate = $this->candidateFromLatestUpdateCheck($package) ?? $this->candidate($package, $setup);
            if ($candidate['version_id'] === $package->source_version_id
                && $this->versionsEquivalent($candidate['version_number'], (string) $package->version_number)) {
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
                $metadata = $this->files->downloadJarWithMetadata(
                    $server,
                    $candidate['url'],
                    $newPath,
                    $candidate['hashes']
                );
                if (!$this->files->exists($server, $newPath)) {
                    throw new MinecraftToolkitException('Das Update wurde vom Dateisystem nicht bestätigt. Die neue Datei wurde nicht gefunden.');
                }
                if ($newPath !== $oldPath && $this->files->exists($server, $oldPath)) {
                    throw new MinecraftToolkitException('Die alte Paketdatei ist nach dem Update noch vorhanden. Das Update wurde zur Sicherheit abgebrochen.');
                }
                $this->assertDownloadedPackageMatchesCandidate($package, $candidate, $metadata);
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
                'sha1' => $metadata['sha1'] ?? ($candidate['hashes']['sha1'] ?? null),
                'sha512' => $metadata['sha512'] ?? ($candidate['hashes']['sha512'] ?? null),
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
                'candidate_json' => Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json') ? $candidate : null,
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


    /** @return array{installed: int, skipped: int, errors: string[]} */
    public function installMissingDependencies(Server $server, MinecraftToolkitSetup $setup, int $packageId): array
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

        $this->state->assertOffline($server);
        $dependencies = $this->missingDependencies($server, $setup, $package);
        if ($dependencies === []) {
            $this->storeCheck($package, 'up_to_date', 'Alle bekannten Pflicht-Abhängigkeiten sind bereits installiert.');

            return ['installed' => 0, 'skipped' => 0, 'errors' => []];
        }

        $installed = 0;
        $skipped = 0;
        $errors = [];
        foreach ($dependencies as $dependency) {
            $dependencyProjectId = is_string($dependency['project_id'] ?? null)
                ? trim((string) $dependency['project_id'])
                : '';
            if ($dependencyProjectId === '') {
                $dependencyProjectId = $this->dependencyProjectIdFromTitle((string) ($dependency['title'] ?? ''));
            }
            if ($dependencyProjectId === '') {
                $skipped++;
                $errors[] = 'Eine Pflicht-Abhängigkeit konnte nicht eindeutig aufgelöst werden.';
                continue;
            }

            try {
                if ($package->source === 'modrinth') {
                    $this->installer->installModrinthPackage($server, $setup, $dependencyProjectId);
                } elseif ($package->source === 'curseforge') {
                    $this->installer->installCurseForgePackage($server, $setup, $dependencyProjectId);
                } else {
                    $skipped++;
                    $errors[] = "Für die Quelle {$package->source} können Dependencies nicht automatisch installiert werden.";
                    continue;
                }
                $installed++;
            } catch (MinecraftToolkitException $exception) {
                if (str_contains($exception->getMessage(), 'bereits von Minecraft Toolkit verwaltet')) {
                    $skipped++;
                    continue;
                }
                $errors[] = $exception->getMessage();
            }
        }

        $status = $errors === [] ? 'up_to_date' : 'error';
        $message = $errors === []
            ? "$installed Pflicht-Abhängigkeit(en) wurden installiert."
            : "$installed Pflicht-Abhängigkeit(en) installiert, " . count($errors) . ' Fehler.';
        $this->storeCheck($package, $status, $message);
        $this->log($server, 'dependencies_installed', $errors === [] ? 'success' : 'warning', $message, [
            'package_id' => $package->id,
            'installed' => $installed,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('installed', 'skipped', 'errors');
    }

    public function deletePackage(Server $server, int $packageId): void
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

        $this->state->assertOffline($server);
        $package = $this->syncPackageWithInstalledState($server, $package);
        $backup = null;
        if ($package->file_path !== '' && $this->files->exists($server, $package->file_path)) {
            $backup = $this->files->backupIfPresent($server, $package->file_path);
        }

        $package->forceFill([
            'enabled' => false,
            'managed' => false,
            'last_checked_at' => now(),
        ])->save();

        $this->log($server, 'package_deleted', 'warning', "{$package->project_name} wurde gesichert und aus dem Plugin-/Mod-Ordner entfernt.", [
            'package_id' => $package->id,
            'backup' => $backup,
        ]);
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


    private function syncPackageWithInstalledState(Server $server, MinecraftToolkitPackage $package): MinecraftToolkitPackage
    {
        try {
            $log = $this->readLatestLog($server);
            $logIsFresh = !$this->latestLogIsOlderThanPackageInstall($log, $package);
            $loaded = $logIsFresh ? $this->loadedPluginVersions($log) : [];
            $actualVersion = $this->matchLoadedVersion($package, $loaded);
            $actualFileName = $this->matchInstalledFileName($server, $package);

            $changes = [];
            if ($actualVersion !== null
                && $package->source !== 'geysermc'
                && !$this->versionsEquivalent($actualVersion, (string) $package->version_number)
            ) {
                $changes['version_number'] = $actualVersion;
            }
            if ($actualFileName !== null && $actualFileName !== $package->file_name) {
                $changes['file_name'] = $actualFileName;
                $changes['file_path'] = dirname($package->file_path ?: '/plugins/x.jar') . '/' . $actualFileName;
            }
            if ($changes !== []) {
                $changes['last_checked_at'] = now();
                $package->forceFill($changes)->save();
                $this->log($server, 'package_synced', 'info', "{$package->project_name} wurde mit der tatsächlich geladenen/installierten Version synchronisiert.", [
                    'package_id' => $package->id,
                    'changes' => $changes,
                ]);
                $package = $package->refresh();
            }
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit could not sync package with installed state.', [
                'server_uuid' => $server->uuid,
                'package_id' => $package->id,
                'exception' => $exception::class,
            ]);
        }

        return $package;
    }

    private function runtimeIssue(Server $server, MinecraftToolkitPackage $package): ?string
    {
        try {
            $log = $this->readLatestLog($server);
        } catch (\Throwable) {
            return null;
        }
        if ($this->latestLogIsOlderThanPackageInstall($log, $package)) {
            return null;
        }

        $fileName = preg_quote($package->file_name, '/');
        if ($fileName !== '' && preg_match('/Could not load.*' . $fileName . '.*?Unknown\/missing dependency plugins: \[([^\]]+)\]/s', $log, $matches)) {
            return "Das Paket konnte zuletzt nicht laden. Fehlende Plugin-Abhängigkeit: {$matches[1]}.";
        }
        if ($fileName !== '' && preg_match('/Could not load.*' . $fileName . '.*?compiled by a more recent version of the Java Runtime.*?class file version ([0-9.]+).*?recognizes class file versions up to ([0-9.]+)/s', $log, $matches)) {
            return "Das Paket benötigt eine neuere Java-Version. Class-Version {$matches[1]} ist installiert, Java auf dem Server unterstützt nur bis {$matches[2]}.";
        }

        $name = preg_quote($this->normalName($package->project_name), '/');
        if ($name !== '' && preg_match('/Could not load[^\n]*(?:' . $name . ')[\s\S]{0,1200}?Unknown\/missing dependency plugins: \[([^\]]+)\]/i', $log, $matches)) {
            return "Das Paket konnte zuletzt nicht laden. Fehlende Plugin-Abhängigkeit: {$matches[1]}.";
        }
        if ($name !== '' && preg_match('/Could not load[^\n]*(?:' . $name . ')[\s\S]{0,1600}?compiled by a more recent version of the Java Runtime[\s\S]{0,400}?class file version ([0-9.]+)[\s\S]{0,400}?recognizes class file versions up to ([0-9.]+)/i', $log, $matches)) {
            return "Das Paket benötigt eine neuere Java-Version. Class-Version {$matches[1]} ist installiert, Java auf dem Server unterstützt nur bis {$matches[2]}.";
        }

        return null;
    }

    private function missingRequiredDependencyIssue(Server $server, MinecraftToolkitPackage $package): ?string
    {
        $dependencies = $package->dependencies_json;
        if (!is_array($dependencies)) {
            $dependencies = [];
        }
        $dependencies = array_merge($dependencies, $this->knownRequiredDependenciesForPackage($package));
        $runtimeDependency = $this->runtimeMissingDependency($server, $package);
        if ($runtimeDependency !== null) {
            $dependencies[] = [
                'project_id' => $this->dependencyProjectIdFromTitle($runtimeDependency),
                'type' => 'required',
                'title' => $runtimeDependency,
                'slug' => $this->dependencyProjectIdFromTitle($runtimeDependency),
            ];
        }

        $installed = $this->installedDependencyIdentifiers($server);
        $missing = collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && !$this->dependencyIsInstalled($dependency, $installed))
            ->pluck('title')
            ->filter()
            ->values()
            ->all();

        if ($missing === []) {
            return null;
        }

        return 'Pflicht-Abhängigkeiten fehlen: ' . implode(', ', $missing) . '. Installiere sie über den Updater nach.';
    }


    /** @return array<int, array<string, mixed>> */
    private function missingDependencies(Server $server, MinecraftToolkitSetup $setup, MinecraftToolkitPackage $package): array
    {
        $dependencies = is_array($package->dependencies_json) ? $package->dependencies_json : [];
        try {
            $candidate = $this->candidate($package, $setup);
            if (is_array($candidate['dependencies'] ?? null)) {
                $dependencies = array_merge($dependencies, $candidate['dependencies']);
            }
        } catch (\Throwable) {
            // Existing metadata and known rules are still useful when the source API is unavailable.
        }
        $dependencies = array_merge($dependencies, $this->knownRequiredDependenciesForPackage($package));
        $runtimeDependency = $this->runtimeMissingDependency($server, $package);
        if ($runtimeDependency !== null) {
            $dependencies[] = [
                'project_id' => $this->dependencyProjectIdFromTitle($runtimeDependency),
                'type' => 'required',
                'title' => $runtimeDependency,
                'slug' => $this->dependencyProjectIdFromTitle($runtimeDependency),
            ];
        }

        $installed = $this->installedDependencyIdentifiers($server);

        return collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && !$this->dependencyIsInstalled($dependency, $installed))
            ->unique(fn (array $dependency): string => strtolower((string) ($dependency['project_id'] ?? $dependency['slug'] ?? $dependency['title'] ?? '')))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function knownRequiredDependenciesForPackage(MinecraftToolkitPackage $package): array
    {
        $slug = strtolower((string) ($package->source_project_slug ?? ''));
        $name = strtolower($package->project_name);
        if ($slug === 'viarewind' || str_contains($name, 'viarewind')) {
            return [[
                'project_id' => $package->source === 'curseforge' ? null : 'viabackwards',
                'type' => 'required',
                'title' => 'ViaBackwards',
                'slug' => 'viabackwards',
            ]];
        }

        return [];
    }

    /** @return array<int, string> */
    private function installedDependencyIdentifiers(Server $server): array
    {
        return MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->get()
            ->flatMap(fn (MinecraftToolkitPackage $package): array => [
                strtolower((string) $package->source_project_id),
                strtolower((string) $package->source_project_slug),
                $this->normalName($package->project_name),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $dependency
     *  @param array<int, string> $installed
     */
    private function dependencyIsInstalled(array $dependency, array $installed): bool
    {
        $identifiers = array_filter([
            strtolower((string) ($dependency['project_id'] ?? '')),
            strtolower((string) ($dependency['slug'] ?? '')),
            $this->normalName((string) ($dependency['title'] ?? '')),
        ]);

        foreach ($identifiers as $identifier) {
            if (in_array($identifier, $installed, true)) {
                return true;
            }
        }

        return false;
    }

    private function runtimeMissingDependency(Server $server, MinecraftToolkitPackage $package): ?string
    {
        try {
            $log = $this->readLatestLog($server);
        } catch (\Throwable) {
            return null;
        }
        if ($this->latestLogIsOlderThanPackageInstall($log, $package)) {
            return null;
        }

        $fileName = preg_quote($package->file_name, '/');
        if ($fileName !== '' && preg_match('/Could not load.*' . $fileName . '.*?Unknown\/missing dependency plugins: \[([^\]]+)\]/s', $log, $matches)) {
            return trim(explode(',', $matches[1])[0]);
        }

        $name = preg_quote($this->normalName($package->project_name), '/');
        if ($name !== '' && preg_match('/Could not load[^
]*(?:' . $name . ')[\s\S]{0,1200}?Unknown\/missing dependency plugins: \[([^\]]+)\]/i', $log, $matches)) {
            return trim(explode(',', $matches[1])[0]);
        }

        return null;
    }

    private function dependencyProjectIdFromTitle(string $title): string
    {
        $normalized = $this->normalName($title);
        return match ($normalized) {
            'viabackwards' => 'viabackwards',
            default => $normalized,
        };
    }


    /** @return array<string, string> */
    private function loadedPluginVersions(string $log): array
    {
        $versions = [];
        if (preg_match_all('/Loading server plugin ([A-Za-z0-9_.+ -]+) v([^\r\n]+)/', $log, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[$this->normalName($match[1])] = trim($match[2]);
            }
        }
        if (preg_match_all('/ - ([A-Za-z0-9_.+ -]+) \(([^)]+)\)/', $log, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[$this->normalName($match[1])] = trim($match[2]);
            }
        }

        return $versions;
    }

    /** @param array<string, string> $loaded */
    private function matchLoadedVersion(MinecraftToolkitPackage $package, array $loaded): ?string
    {
        $candidates = array_unique(array_filter([
            $this->normalName($package->project_name),
            $this->normalName($package->source_project_slug ?? ''),
            $this->normalName(pathinfo($package->file_name ?? '', PATHINFO_FILENAME)),
            $this->normalName(str_replace(['-bukkit', '-spigot', '-paper'], '', pathinfo($package->file_name ?? '', PATHINFO_FILENAME))),
        ]));

        $aliases = [
            'essentialsx' => ['essentials'],
            'geyser' => ['geyser-spigot', 'geysermc'],
            'floodgate' => ['floodgate-spigot'],
            'simplevoicechat' => ['voicechat', 'voicechat-bukkit'],
            'multiversecore' => ['multiverse-core'],
        ];
        foreach ($candidates as $candidate) {
            foreach ($aliases[$candidate] ?? [] as $alias) {
                $candidates[] = $this->normalName($alias);
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (isset($loaded[$candidate])) {
                return $this->cleanLoadedVersion($loaded[$candidate]);
            }
        }

        return null;
    }

    private function matchInstalledFileName(Server $server, MinecraftToolkitPackage $package): ?string
    {
        $directory = dirname($package->file_path ?: '');
        if (!in_array($directory, ['/plugins', '/mods'], true)) {
            return null;
        }

        $target = $this->normalName($package->project_name);
        foreach ($this->files->listDirectory($server, $directory) as $file) {
            $name = is_string($file['name'] ?? null) ? $file['name'] : '';
            if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'jar') {
                continue;
            }

            $normalized = $this->normalName(pathinfo($name, PATHINFO_FILENAME));
            if ($normalized !== '' && ($normalized === $target || str_contains($normalized, $target) || str_contains($target, $normalized))) {
                return $name;
            }
        }

        return null;
    }


    private function latestLogIsOlderThanPackageInstall(string $log, MinecraftToolkitPackage $package): bool
    {
        if ($package->installed_at === null || $log === '') {
            return false;
        }
        if (!preg_match_all('/\[([0-2][0-9]:[0-5][0-9]:[0-5][0-9])\]/', $log, $matches) || $matches[1] === []) {
            return false;
        }

        $last = end($matches[1]);
        if (!is_string($last)) {
            return false;
        }

        try {
            $logTime = $package->installed_at->copy()->setTimeFromTimeString($last);
            if ($logTime->greaterThan(now()->addMinute())) {
                $logTime = $logTime->subDay();
            }

            return $logTime->lessThan($package->installed_at->copy()->subSeconds(5));
        } catch (\Throwable) {
            return false;
        }
    }

    private function versionsEquivalent(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === $b) {
            return true;
        }

        return $this->versionBase($a) === $this->versionBase($b);
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


    private function readLatestLog(Server $server): string
    {
        try {
            return $this->files->read($server, '/logs/latest.log', 1024 * 1024);
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\.(jar)$/', '', $name) ?? $name;
        $name = preg_replace('/\b(bukkit|spigot|paper|plugin|mod)\b/', '', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;

        return trim($name);
    }

    private function cleanLoadedVersion(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/-SNAPSHOT(?:\s*\([^)]*\))?$/', '', $version) ?? $version;
        $version = preg_replace('/\s*\([^)]*\)$/', '', $version) ?? $version;

        return trim($version);
    }


    /** @return array<string, mixed>|null */
    private function candidateFromLatestUpdateCheck(MinecraftToolkitPackage $package): ?array
    {
        if (!Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json')) {
            return null;
        }

        $check = MinecraftToolkitUpdateCheck::query()
            ->where('server_uuid', $package->server_uuid)
            ->where('package_id', $package->id)
            ->where('status', 'update_available')
            ->latest('id')
            ->first();

        if (!$check instanceof MinecraftToolkitUpdateCheck || !is_array($check->candidate_json)) {
            return null;
        }

        $candidate = $check->candidate_json;
        foreach (['version_id', 'version_number', 'file_name', 'url', 'hashes', 'dependencies'] as $key) {
            if (!array_key_exists($key, $candidate)) {
                return null;
            }
        }

        // Only reuse the exact candidate from the latest check when it still targets the
        // currently installed package version. This avoids installing a stale candidate
        // after a manual file replacement.
        if ((string) ($check->old_version_id ?? '') !== (string) ($package->source_version_id ?? '')
            && !$this->versionsEquivalent((string) ($check->old_version_number ?? ''), (string) ($package->version_number ?? ''))) {
            return null;
        }

        return $candidate;
    }

    /** @param array<string, mixed> $candidate
     *  @param array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string} $metadata
     */
    private function assertDownloadedPackageMatchesCandidate(
        MinecraftToolkitPackage $package,
        array $candidate,
        array $metadata
    ): void {
        $downloadedVersion = $metadata['plugin_version'] ?? null;
        if (!is_string($downloadedVersion) || $downloadedVersion === '') {
            return;
        }

        if ($package->source === 'geysermc') {
            return;
        }

        $expected = (string) ($candidate['version_number'] ?? '');
        if ($expected === '') {
            return;
        }

        if (!$this->versionsEquivalent($downloadedVersion, $expected)) {
            throw new MinecraftToolkitException(
                "Die heruntergeladene Datei enthält Version $downloadedVersion, erwartet wurde aber $expected. Das Update wurde abgebrochen, weil die Quelle eine falsche/alte Datei geliefert hat."
            );
        }
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
            'candidate_json' => Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json') ? $candidate : null,
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
