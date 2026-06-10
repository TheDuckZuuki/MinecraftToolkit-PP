<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use BlueWolf\MinecraftToolkit\Services\CurseForgeApiKeyProvider;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;

class MinecraftToolkitPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecrafttoolkit';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "BlueWolf\\MinecraftToolkit\\Filament\\$id\\Pages"
        );
    }

    public function boot(Panel $panel): void {}

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('enabled')
                ->label(trans('minecrafttoolkit::strings.settings.enabled'))
                ->default((bool) config('minecrafttoolkit.enabled', true)),
            Toggle::make('admins_only')
                ->label(trans('minecrafttoolkit::strings.settings.admins_only'))
                ->default((bool) config('minecrafttoolkit.admins_only', false)),
            Toggle::make('backup_before_overwrite')
                ->label(trans('minecrafttoolkit::strings.settings.backup_before_overwrite'))
                ->default((bool) config('minecrafttoolkit.backup_before_overwrite', true)),
            Toggle::make('modrinth_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.modrinth_enabled'))
                ->default((bool) config('minecrafttoolkit.modrinth_enabled', true)),
            Toggle::make('curseforge_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.curseforge_enabled'))
                ->default((bool) config('minecrafttoolkit.curseforge_enabled', false))
                ->helperText(trans('minecrafttoolkit::strings.settings.curseforge_enabled_help')),
            Placeholder::make('curseforge_key_status')
                ->label(trans('minecrafttoolkit::strings.settings.curseforge_key_status'))
                ->content(fn (): string => app(CurseForgeApiKeyProvider::class)->hasKey()
                    ? trans('minecrafttoolkit::strings.settings.direct_key_available', ['source' => app(CurseForgeApiKeyProvider::class)->source() ?? trans('minecrafttoolkit::strings.settings.unknown_source')])
                    : ((string) config('minecrafttoolkit.curseforge_proxy_url', '') !== ''
                        ? trans('minecrafttoolkit::strings.settings.proxy_active', ['url' => (string) config('minecrafttoolkit.curseforge_proxy_url')])
                         : trans('minecrafttoolkit::strings.settings.no_proxy_no_key'))) ,
            TextInput::make('curseforge_proxy_url')
                ->label(trans('minecrafttoolkit::strings.settings.proxy_url'))
                ->url()
                ->default((string) config('minecrafttoolkit.curseforge_proxy_url', ''))
                ->helperText(trans('minecrafttoolkit::strings.settings.proxy_url_help')),
            TextInput::make('curseforge_proxy_secret')
                ->label(trans('minecrafttoolkit::strings.settings.proxy_secret'))
                ->password()
                ->revealable()
                ->default((string) config('minecrafttoolkit.curseforge_proxy_secret', ''))
                ->helperText(trans('minecrafttoolkit::strings.settings.proxy_secret_help')),
            TextInput::make('curseforge_api_key')
                ->label(trans('minecrafttoolkit::strings.settings.api_key_override'))
                ->password()
                ->revealable()
                ->default((string) config('minecrafttoolkit.curseforge_api_key', ''))
                ->helperText(trans('minecrafttoolkit::strings.settings.api_key_override_help')),
            Toggle::make('updater_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.updater_enabled'))
                ->default((bool) config('minecrafttoolkit.updater_enabled', true)),
            Toggle::make('version_change_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.version_change_enabled'))
                ->default((bool) config('minecrafttoolkit.version_change_enabled', true)),
            Toggle::make('version_change_users_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.version_change_users_enabled'))
                ->default((bool) config('minecrafttoolkit.version_change_users_enabled', true)),
            Toggle::make('crossplay_enabled')
                ->label(trans('minecrafttoolkit::strings.settings.crossplay_enabled'))
                ->default((bool) config('minecrafttoolkit.crossplay_enabled', true)),
            Toggle::make('bedrock_port_required')
                ->label(trans('minecrafttoolkit::strings.settings.bedrock_port_required'))
                ->default((bool) config('minecrafttoolkit.bedrock_port_required', true)),
            TextInput::make('http_timeout')
                ->label(trans('minecrafttoolkit::strings.settings.http_timeout'))
                ->numeric()
                ->minValue(5)
                ->required()
                ->default((int) config('minecrafttoolkit.http_timeout', 20)),
            TextInput::make('download_timeout')
                ->label(trans('minecrafttoolkit::strings.settings.download_timeout'))
                ->numeric()
                ->minValue(30)
                ->required()
                ->default((int) config('minecrafttoolkit.download_timeout', 300)),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MINECRAFT_TOOLKIT_ENABLED' => (bool) ($data['enabled'] ?? false),
            'MINECRAFT_TOOLKIT_ADMINS_ONLY' => (bool) ($data['admins_only'] ?? false),
            'MINECRAFT_TOOLKIT_BACKUP_BEFORE_OVERWRITE' => (bool) ($data['backup_before_overwrite'] ?? true),
            'MINECRAFT_TOOLKIT_MODRINTH_ENABLED' => (bool) ($data['modrinth_enabled'] ?? true),
            'MINECRAFT_TOOLKIT_CURSEFORGE_ENABLED' => (bool) ($data['curseforge_enabled'] ?? false),
            'MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL' => rtrim(trim((string) ($data['curseforge_proxy_url'] ?? '')), '/'),
            'MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET' => trim((string) ($data['curseforge_proxy_secret'] ?? '')),
            'MINECRAFT_TOOLKIT_CURSEFORGE_API_KEY' => trim((string) ($data['curseforge_api_key'] ?? '')),
            'MINECRAFT_TOOLKIT_UPDATER_ENABLED' => (bool) ($data['updater_enabled'] ?? true),
            'MINECRAFT_TOOLKIT_VERSION_CHANGE_ENABLED' => (bool) ($data['version_change_enabled'] ?? true),
            'MINECRAFT_TOOLKIT_VERSION_CHANGE_USERS_ENABLED' => (bool) ($data['version_change_users_enabled'] ?? true),
            'MINECRAFT_TOOLKIT_CROSSPLAY_ENABLED' => (bool) ($data['crossplay_enabled'] ?? true),
            'MINECRAFT_TOOLKIT_BEDROCK_PORT_REQUIRED' => (bool) ($data['bedrock_port_required'] ?? true),
            'MINECRAFT_TOOLKIT_HTTP_TIMEOUT' => max(5, (int) ($data['http_timeout'] ?? 20)),
            'MINECRAFT_TOOLKIT_DOWNLOAD_TIMEOUT' => max(30, (int) ($data['download_timeout'] ?? 300)),
        ]);
    }
}
