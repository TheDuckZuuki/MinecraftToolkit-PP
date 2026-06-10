<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Filament\Server\Pages\MinecraftOverviewPage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftSetupService;
use BlueWolf\MinecraftToolkit\Services\MinecraftSoftwareService;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * @property \Filament\Schemas\Schema $form
 */
class MinecraftSetupPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-minecraft';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'minecraft-setup';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-setup';

    public ?array $data = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->form->fill($this->defaults());
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('minecrafttoolkit.enabled', true)
            || collect([
                'minecraft_toolkit_setups',
                'minecraft_toolkit_packages',
                'minecraft_toolkit_update_checks',
                'minecraft_toolkit_logs',
            ])->contains(fn (string $table): bool => !Schema::hasTable($table))) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();
        if (!$server instanceof Server || $user === null
            || !app(MinecraftPermissionService::class)->canModify($user, $server)) {
            return false;
        }

        return !MinecraftToolkitSetup::query()
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
        return 'Minecraft Setup';
    }

    public function getTitle(): string
    {
        return 'Minecraft Setup';
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Wizard::make([
                Step::make('software')
                    ->label('Serversoftware')
                    ->icon('tabler-box')
                    ->schema([
                        Select::make('software')
                            ->label('Serversoftware')
                            ->options(app(MinecraftSoftwareService::class)->supportedSoftware())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('minecraft_version', null);
                                $set('loader_version', null);
                                $set('crossplay_enabled', false);
                            })
                            ->required()
                            ->helperText('Vanilla Java, Vanilla Bedrock, Paper, Folia, Purpur sowie Fabric, Forge und NeoForge werden unterstützt.'),
                    ]),
                Step::make('version')
                    ->label('Minecraft-Version')
                    ->icon('tabler-tag')
                    ->schema([
                        Select::make('minecraft_version')
                            ->label('Minecraft-Version')
                            ->options(fn (Get $get): array => app(MinecraftSoftwareService::class)
                                ->versionOptions((string) ($get('software') ?: 'vanilla')))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('loader_version', null))
                            ->required(),
                        Select::make('loader_version')
                            ->label('Loader-Version')
                            ->options(fn (Get $get): array => app(MinecraftSoftwareService::class)->loaderVersionOptions(
                                (string) $get('software'),
                                (string) $get('minecraft_version')
                            ))
                            ->visible(fn (Get $get): bool => in_array(
                                $get('software'),
                                ['fabric', 'forge', 'neoforge'],
                                true
                            ))
                            ->searchable()
                            ->required(fn (Get $get): bool => in_array(
                                $get('software'),
                                ['fabric', 'forge', 'neoforge'],
                                true
                            )),
                    ]),
                Step::make('settings')
                    ->label('Server-Einstellungen')
                    ->icon('tabler-adjustments')
                    ->schema([
                        Section::make('Welt und Spieler')
                            ->schema([
                                TextInput::make('motd')->label('MOTD')->required()->maxLength(255),
                                TextInput::make('level_name')->label('Level-Name')->required()->maxLength(64),
                                TextInput::make('max_players')->label('Maximale Spieler')->numeric()->minValue(1)->maxValue(100000)->required(),
                                Select::make('gamemode')->label('Spielmodus')->options([
                                    'survival' => 'Survival',
                                    'creative' => 'Creative',
                                    'adventure' => 'Adventure',
                                    'spectator' => 'Spectator',
                                ])->required(),
                                Select::make('difficulty')->label('Schwierigkeit')->options([
                                    'peaceful' => 'Peaceful',
                                    'easy' => 'Easy',
                                    'normal' => 'Normal',
                                    'hard' => 'Hard',
                                ])->required(),
                            ])->columns(2),
                        Section::make('Gameplay')
                            ->schema([
                                Group::make()->columns(3)->schema([
                                    Toggle::make('online_mode')->label('Online Mode'),
                                    Toggle::make('whitelist')->label('Whitelist'),
                                    Toggle::make('pvp')->label('PVP'),
                                    Toggle::make('allow_nether')->label('Nether erlauben'),
                                    Toggle::make('enable_command_block')->label('Command Blocks'),
                                    Toggle::make('allow_flight')->label('Fliegen erlauben'),
                                    Toggle::make('enable_query')->label('Query aktivieren'),
                                    Toggle::make('enable_rcon')->label('RCON aktivieren'),
                                ]),
                                Group::make()->columns(3)->schema([
                                    TextInput::make('spawn_protection')->label('Spawn-Schutz')->numeric()->minValue(0)->maxValue(1000)->required(),
                                    TextInput::make('view_distance')->label('Sichtweite')->numeric()->minValue(2)->maxValue(32)->required(),
                                    TextInput::make('simulation_distance')->label('Simulationsweite')->numeric()->minValue(2)->maxValue(32)->required(),
                                ]),
                            ]),
                    ]),
                Step::make('icon')
                    ->label('Server-Icon')
                    ->icon('tabler-photo')
                    ->visible(fn (Get $get): bool => $get('software') !== 'bedrock')
                    ->schema([
                        FileUpload::make('server_icon')
                            ->label('Server-Icon')
                            ->image()
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(2048)
                            ->storeFiles(false)
                            ->helperText('Optional. Erforderlich sind exakt 64×64 Pixel als PNG.'),
                    ]),
                Step::make('crossplay')
                    ->label('Crossplay')
                    ->icon('tabler-device-gamepad-2')
                    ->visible(fn (Get $get): bool => (bool) config('minecrafttoolkit.crossplay_enabled', true)
                        && in_array($get('software'), ['paper', 'purpur'], true))
                    ->schema([
                        Toggle::make('crossplay_enabled')
                            ->label('Crossplay aktivieren')
                            ->helperText('Installiert Geyser-Spigot und Floodgate-Spigot als verwaltete System-Plugins.')
                            ->live(),
                        Select::make('bedrock_allocation_id')
                            ->label('Bedrock-Port Allocation')
                            ->options(fn (): array => $this->bedrockAllocationOptions())
                            ->required(fn (Get $get): bool => (bool) $get('crossplay_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('crossplay_enabled'))
                            ->helperText('Geyser benötigt normalerweise eine zusätzliche UDP-Allocation für Bedrock-Spieler.'),
                        Section::make('Hinweis')
                            ->description('Geyser erlaubt Bedrock-Spielern den Beitritt. Floodgate ermöglicht den Beitritt ohne Java-Account. Nach dem ersten Serverstart muss die erzeugte Geyser-Konfiguration einmal angewendet werden.')
                            ->schema([]),
                    ]),
                Step::make('review')
                    ->label('Prüfen')
                    ->icon('tabler-list-check')
                    ->schema([
                        Section::make('Bereit für das Setup')
                            ->description(function (Get $get): string {
                                $software = (string) $get('software');
                                $artifact = match ($software) {
                                    'bedrock' => 'bedrock-server.zip',
                                    'forge' => 'forge-installer.jar',
                                    'neoforge' => 'neoforge-installer.jar',
                                    default => 'server.jar',
                                };

                                return sprintf(
                                    '%s %s wird über %s eingerichtet. Port %s kommt aus der primären Allocation; vorhandene Zieldateien werden gesichert.',
                                    app(MinecraftSoftwareService::class)->supportedSoftware()[$software] ?? 'Minecraft',
                                    trim(($get('minecraft_version') ?: '') . ' ' . ($get('loader_version') ?: '')),
                                    $artifact,
                                    Filament::getTenant()?->allocation?->port ?? 'fehlt'
                                );
                            })
                            ->schema([
                                Hidden::make('review_confirmed')->default(true),
                            ]),
                    ]),
            ])->columnSpanFull(),
        ];
    }

    public function setup(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $state = $this->form->getState();
            $icon = $state['server_icon'] ?? null;
            $usesBootstrapInstaller = in_array($state['software'] ?? null, ['forge', 'neoforge', 'bedrock'], true);
            unset($state['server_icon'], $state['review_confirmed']);

            app(MinecraftSetupService::class)->setup($server, $state, $icon);

            Notification::make()
                ->title('Minecraft-Setup abgeschlossen')
                ->body($usesBootstrapInstaller
                    ? 'Der Loader-Installer liegt bereit. Beim ersten Start werden die Laufzeitdateien erzeugt; das kann einige Minuten dauern.'
                    : 'Serversoftware, eula.txt und server.properties wurden über Wings eingerichtet.')
                ->success()
                ->send();

            $this->redirect(MinecraftOverviewPage::getUrl(panel: 'server', tenant: $server));
        } catch (MinecraftToolkitException $exception) {
            Notification::make()
                ->title('Minecraft-Setup fehlgeschlagen')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function defaults(): array
    {
        return [
            'software' => 'paper',
            'loader_version' => null,
            'motd' => 'A Minecraft Server',
            'level_name' => 'world',
            'max_players' => 20,
            'gamemode' => 'survival',
            'difficulty' => 'easy',
            'online_mode' => true,
            'whitelist' => false,
            'pvp' => true,
            'allow_nether' => true,
            'spawn_protection' => 16,
            'view_distance' => 10,
            'simulation_distance' => 10,
            'enable_command_block' => false,
            'allow_flight' => false,
            'enable_query' => false,
            'enable_rcon' => false,
            'crossplay_enabled' => false,
            'bedrock_allocation_id' => null,
        ];
    }

    /** @return array<int, string> */
    private function bedrockAllocationOptions(): array
    {
        $server = Filament::getTenant();
        if (!$server instanceof Server) {
            return [];
        }

        $allocations = $server->allocations()
            ->where('id', '!=', $server->allocation_id)
            ->where('node_id', $server->node_id)
            ->get()
            ->mapWithKeys(fn ($allocation): array => [
                $allocation->id => $allocation->address,
            ])
            ->all();

        if (!(bool) config('minecrafttoolkit.bedrock_port_required', true) && $server->allocation) {
            return [$server->allocation->id => $server->allocation->address . ' (geteilt)'] + $allocations;
        }

        return $allocations;
    }
}
