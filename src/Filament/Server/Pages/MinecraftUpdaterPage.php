<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitUpdateCheck;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftUpdateService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class MinecraftUpdaterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-refresh';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'minecraft-updater';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-updater';

    /** @var array<int, array<string, mixed>> */
    public array $packages = [];

    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->refreshPackages();
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('minecrafttoolkit.enabled', true)
            || !(bool) config('minecrafttoolkit.updater_enabled', true)
            || !Schema::hasTable('minecraft_toolkit_setups')
            || !Schema::hasTable('minecraft_toolkit_packages')
            || !Schema::hasTable('minecraft_toolkit_update_checks')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && app(MinecraftPermissionService::class)->canModify($user, $server)
            && MinecraftToolkitSetup::query()
                ->where('server_uuid', $server->uuid)
                ->where('setup_status', 'completed')
                ->exists();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecrafttoolkit::strings.navigation.updater');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.updater');
    }

    public function checkUpdates(): void
    {
        try {
            $checks = app(MinecraftUpdateService::class)->checkAll($this->server(), $this->setup());
            $available = collect($checks)->where('status', 'update_available')->count();

            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.check_complete'))
                ->body($available === 1
                    ? 'Für ein Paket ist ein Update verfügbar.'
                    : "Für $available Pakete sind Updates verfügbar.")
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Updateprüfung fehlgeschlagen', $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Updateprüfung fehlgeschlagen');
        }
    }

    public function updatePackage(int $packageId): void
    {
        try {
            $package = app(MinecraftUpdateService::class)
                ->updatePackage($this->server(), $this->setup(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.package_updated', ['name' => $package->project_name]))
                ->body("Installierte Version: {$package->version_number}")
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Update fehlgeschlagen', $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Update fehlgeschlagen');
            $this->refreshPackages();
        }
    }


    public function installDependencies(int $packageId): void
    {
        try {
            $result = app(MinecraftUpdateService::class)
                ->installMissingDependencies($this->server(), $this->setup(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.dependencies_installed'))
                ->body("Installiert: {$result['installed']}, übersprungen: {$result['skipped']}, Fehler: " . count($result['errors']))
                ->status(count($result['errors']) > 0 ? 'warning' : 'success')
                ->persistent(count($result['errors']) > 0)
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Dependency-Installation fehlgeschlagen', $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Dependency-Installation fehlgeschlagen');
            $this->refreshPackages();
        }
    }

    public function deletePackage(int $packageId): void
    {
        try {
            app(MinecraftUpdateService::class)->deletePackage($this->server(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.package_deleted'))
                ->body(trans('minecrafttoolkit::strings.updater.package_deleted_body'))
                ->warning()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Paket konnte nicht gelöscht werden', $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Paket konnte nicht gelöscht werden');
            $this->refreshPackages();
        }
    }

    public function updateAll(): void
    {
        try {
            $result = app(MinecraftUpdateService::class)->updateAll($this->server(), $this->setup());
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.updates_complete'))
                ->body("Aktualisiert: {$result['updated']}, fehlgeschlagen: {$result['failed']}, gepinnt uebersprungen: {$result['skipped_pinned']}")
                ->status($result['failed'] > 0 ? 'warning' : 'success')
                ->persistent($result['failed'] > 0)
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Updates fehlgeschlagen', $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Updates fehlgeschlagen');
        }
    }

    public function pinPackage(int $packageId): void
    {
        $this->setPackagePinned($packageId, true);
    }

    public function unpinPackage(int $packageId): void
    {
        $this->setPackagePinned($packageId, false);
    }

    public function pinAllPackages(): void
    {
        $count = $this->setAllPackagesPinned(true);

        Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_pin_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_changed', ['count' => $count]))
            ->success()
            ->send();
    }

    public function unpinAllPackages(): void
    {
        $count = $this->setAllPackagesPinned(false);

        Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_unpin_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_changed', ['count' => $count]))
            ->success()
            ->send();
    }

    public function verifyPackage(int $packageId): void
    {
        try {
            $result = app(MinecraftUpdateService::class)->verifyPackage($this->server(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.verify_complete'))
                ->body($result['message'])
                ->status($result['status'] === 'verified' ? 'success' : 'danger')
                ->persistent($result['status'] !== 'verified')
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.verify_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.verify_failed'));
            $this->refreshPackages();
        }
    }

    public function verifyAllPackages(): void
    {
        $verified = 0;
        $failed = 0;

        foreach ($this->packages as $package) {
            try {
                $result = app(MinecraftUpdateService::class)->verifyPackage($this->server(), (int) $package['id']);
                if (($result['status'] ?? null) === 'verified') {
                    $verified++;
                } else {
                    $failed++;
                }
            } catch (MinecraftToolkitException $exception) {
                $failed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_verify_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_verify_body', [
                'verified' => $verified,
                'failed' => $failed,
            ]))
            ->status($failed > 0 ? 'warning' : 'success')
            ->persistent($failed > 0)
            ->send();

        $this->refreshPackages();
    }

    private function setPackagePinned(int $packageId, bool $pinned): void
    {
        try {
            app(MinecraftUpdateService::class)->setPackagePinned($this->server(), $packageId, $pinned);
            Notification::make()
                ->title($pinned
                    ? trans('minecrafttoolkit::strings.updater.package_pinned')
                    : trans('minecrafttoolkit::strings.updater.package_unpinned'))
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Pin-Aktion fehlgeschlagen', $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Pin-Aktion fehlgeschlagen');
            $this->refreshPackages();
        }
    }

    private function setAllPackagesPinned(bool $pinned): int
    {
        $count = MinecraftToolkitPackage::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->whereIn('package_type', ['plugin', 'mod', 'crossplay', 'dependency'])
            ->update(['update_pinned' => $pinned]);

        $this->refreshPackages();

        return $count;
    }

    private function refreshPackages(): void
    {
        $this->packages = MinecraftToolkitPackage::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->whereIn('package_type', ['plugin', 'mod', 'crossplay', 'dependency'])
            ->orderBy('project_name')
            ->get()
            ->map(function (MinecraftToolkitPackage $package): array {
                $check = MinecraftToolkitUpdateCheck::query()
                    ->where('package_id', $package->id)
                    ->latest('id')
                    ->first();

                return [
                    'id' => $package->id,
                    'name' => $package->project_name,
                    'source' => $package->source,
                    'type' => $package->package_type,
                    'current_version' => $package->version_number,
                    'new_version' => $check?->new_version_number,
                    'status' => $check?->status ?? 'unchecked',
                    'message' => $check?->message,
                    'checked_at' => $check?->checked_at?->diffForHumans(),
                    'system' => $package->is_system_package,
                    'pinned' => (bool) $package->update_pinned,
                    'health' => $this->packageHealth($package, $check),
                    'can_install_dependencies' => $check?->status === 'error'
                        && is_string($check?->message)
                        && (str_contains($check->message, 'Pflicht-Abhängigkeiten')
                            || str_contains($check->message, 'Fehlende Plugin-Abhängigkeit')),
                    'can_delete' => !$package->is_system_package,
                ];
            })
            ->all();

        $this->history = MinecraftToolkitUpdateCheck::query()
            ->with('package')
            ->where('server_uuid', $this->server()->uuid)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (MinecraftToolkitUpdateCheck $check): array => [
                'package' => $check->package?->project_name ?? 'Gelöschtes Paket',
                'status' => $check->status,
                'old_version' => $check->old_version_number,
                'new_version' => $check->new_version_number,
                'message' => $check->message,
                'checked_at' => $check->checked_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }

    /** @return array{score: int, label: string, color: string, reasons: array<int, string>} */
    private function packageHealth(MinecraftToolkitPackage $package, ?MinecraftToolkitUpdateCheck $check): array
    {
        $score = 75;
        $reasons = [];

        if (in_array($package->source, ['modrinth', 'geysermc'], true)) {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_trusted_source');
        } elseif ($package->source === 'curseforge') {
            $score += 5;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_known_source');
        }

        if (is_string($package->sha512) && $package->sha512 !== '') {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_sha512');
        } elseif (is_string($package->sha1) && $package->sha1 !== '') {
            $score += 3;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_sha1');
        } else {
            $score -= 20;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_no_hash');
        }

        if ($package->update_pinned) {
            $score -= 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_pinned');
        }

        if ($check?->status === 'verified') {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_verified');
        } elseif ($check?->status === 'error') {
            $score -= 25;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_error');
        } elseif ($check?->status === 'update_available') {
            $score -= 5;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_update_available');
        }

        $score = min(100, max(0, $score));

        return [
            'score' => $score,
            'label' => $score >= 85
                ? trans('minecrafttoolkit::strings.updater.health_good')
                : ($score >= 60
                    ? trans('minecrafttoolkit::strings.updater.health_ok')
                    : trans('minecrafttoolkit::strings.updater.health_attention')),
            'color' => $score >= 85 ? 'text-success-600' : ($score >= 60 ? 'text-warning-600' : 'text-danger-600'),
            'reasons' => array_slice($reasons, 0, 3),
        ];
    }

    private function setup(): MinecraftToolkitSetup
    {
        return MinecraftToolkitSetup::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('setup_status', 'completed')
            ->firstOrFail();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function notifyError(string $title, MinecraftToolkitException $exception): void
    {
        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    private function notifyUnexpectedError(string $title): void
    {
        Notification::make()
            ->title($title)
            ->body('Wings oder eine externe Paketquelle ist derzeit nicht erreichbar. Technische Details wurden protokolliert.')
            ->danger()
            ->persistent()
            ->send();
    }
}
