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
        return 'Minecraft Updater';
    }

    public function getTitle(): string
    {
        return 'Minecraft Updater';
    }

    public function checkUpdates(): void
    {
        try {
            $checks = app(MinecraftUpdateService::class)->checkAll($this->server(), $this->setup());
            $available = collect($checks)->where('status', 'update_available')->count();

            Notification::make()
                ->title('Updateprüfung abgeschlossen')
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
                ->title("{$package->project_name} wurde aktualisiert")
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

    public function updateAll(): void
    {
        try {
            $result = app(MinecraftUpdateService::class)->updateAll($this->server(), $this->setup());
            Notification::make()
                ->title('Paketupdates abgeschlossen')
                ->body("Aktualisiert: {$result['updated']}, fehlgeschlagen: {$result['failed']}")
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
