<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Providers;

use BlueWolf\MinecraftToolkit\Services\MinecraftPropertiesService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPackageInstaller;
use BlueWolf\MinecraftToolkit\Services\MinecraftCrossplayService;
use BlueWolf\MinecraftToolkit\Services\GeyserDownloadService;
use BlueWolf\MinecraftToolkit\Services\ModrinthService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerStateService;
use BlueWolf\MinecraftToolkit\Services\MinecraftSetupService;
use BlueWolf\MinecraftToolkit\Services\MinecraftSoftwareService;
use BlueWolf\MinecraftToolkit\Services\MinecraftUpdateService;
use BlueWolf\MinecraftToolkit\Services\MinecraftCompatibilityService;
use BlueWolf\MinecraftToolkit\Services\MinecraftVersionChangeService;
use BlueWolf\MinecraftToolkit\Services\CurseForgeApiKeyProvider;
use BlueWolf\MinecraftToolkit\Services\CurseForgeService;
use Illuminate\Support\ServiceProvider;

class MinecraftToolkitPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MinecraftPropertiesService::class);
        $this->app->singleton(MinecraftPermissionService::class);
        $this->app->singleton(ModrinthService::class);
        $this->app->singleton(CurseForgeApiKeyProvider::class);
        $this->app->singleton(CurseForgeService::class);
        $this->app->singleton(MinecraftPackageInstaller::class);
        $this->app->singleton(GeyserDownloadService::class);
        $this->app->singleton(MinecraftCrossplayService::class);
        $this->app->singleton(MinecraftSoftwareService::class);
        $this->app->singleton(MinecraftUpdateService::class);
        $this->app->singleton(MinecraftCompatibilityService::class);
        $this->app->singleton(MinecraftVersionChangeService::class);
        $this->app->singleton(MinecraftServerFileService::class);
        $this->app->singleton(MinecraftServerStateService::class);
        $this->app->singleton(MinecraftSetupService::class);
    }

    public function boot(): void {}
}
