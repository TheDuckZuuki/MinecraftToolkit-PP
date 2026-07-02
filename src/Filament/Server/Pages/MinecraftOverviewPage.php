<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\CurseForgeService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class MinecraftOverviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-minecraft';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'minecraft-overview';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-overview';

    public ?MinecraftToolkitSetup $setup = null;

    public array $logs = [];

    public array $backups = [];

    public array $sourceStatuses = [];

    public int $packageCount = 0;

    public function mount(): void
    {
        $this->authorizeAccess();
        /** @var Server $server */
        $server = Filament::getTenant();
        $this->setup = MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->first();
        $this->packageCount = Schema::hasTable('minecraft_toolkit_packages')
            ? MinecraftToolkitPackage::query()
                ->where('server_uuid', $server->uuid)
                ->whereIn('package_type', ['plugin', 'mod'])
                ->where('enabled', true)
                ->count()
            : 0;
        $this->logs = Schema::hasTable('minecraft_toolkit_logs')
            ? MinecraftToolkitLog::query()
                ->where('server_uuid', $server->uuid)
                ->latest('id')
                ->limit(10)
                ->get()
                ->all()
            : [];
        $this->backups = app(MinecraftServerFileService::class)->listBackups($server);
        $this->sourceStatuses = [
            [
                'name' => 'Modrinth',
                'enabled' => (bool) config('minecrafttoolkit.modrinth_enabled', true),
                'detail' => (bool) config('minecrafttoolkit.modrinth_enabled', true) ? 'enabled' : 'disabled',
            ],
            [
                'name' => 'CurseForge',
                'enabled' => app(CurseForgeService::class)->isConfigured(),
                'detail' => app(CurseForgeService::class)->keySource() ?? 'not configured',
            ],
            [
                'name' => 'Crossplay',
                'enabled' => (bool) config('minecrafttoolkit.crossplay_enabled', true),
                'detail' => (bool) config('minecrafttoolkit.crossplay_enabled', true) ? 'enabled' : 'disabled',
            ],
        ];
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('minecrafttoolkit.enabled', true)
            || !Schema::hasTable('minecraft_toolkit_setups')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && app(MinecraftPermissionService::class)->canView($user, $server)
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
        return trans('minecrafttoolkit::strings.navigation.overview');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.overview');
    }
}
