<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftCrossplayService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPropertiesService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerStateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * @property \Filament\Schemas\Schema $form
 */
class MinecraftSettingsPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-settings';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 23;

    protected static ?string $slug = 'minecraft-settings';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        /** @var Server $server */
        $server = Filament::getTenant();
        $setup = MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->firstOrFail();
        $this->form->fill($setup->only([
            'motd',
            'max_players',
            'online_mode',
            'whitelist',
            'pvp',
            'allow_flight',
            'view_distance',
            'simulation_distance',
        ]) + [
            'bedrock_allocation_id' => $this->currentBedrockAllocationId($server, $setup),
        ]);
    }

    public static function canAccess(): bool
    {
        if (!Schema::hasTable('minecraft_toolkit_setups')) {
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
        return 'Minecraft Settings';
    }

    public function getTitle(): string
    {
        return 'Minecraft Settings';
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('server.properties')
                ->description('Nur die angezeigten Werte werden geändert. Der Server muss gestoppt sein.')
                ->columns(2)
                ->schema([
                    TextInput::make('motd')->label('MOTD')->required()->maxLength(255),
                    TextInput::make('max_players')->label('Maximale Spieler')->numeric()->minValue(1)->required(),
                    TextInput::make('view_distance')->label('Sichtweite')->numeric()->minValue(2)->maxValue(32)->required(),
                    TextInput::make('simulation_distance')->label('Simulationsweite')->numeric()->minValue(2)->maxValue(32)->required(),
                    Toggle::make('online_mode')->label('Online Mode'),
                    Toggle::make('whitelist')->label('Whitelist'),
                    Toggle::make('pvp')->label('PVP'),
                    Toggle::make('allow_flight')->label('Fliegen erlauben'),
                ]),
            Section::make('Crossplay')
                ->description('Geyser und Floodgate werden als geschützte Systempakete installiert. Der Server muss gestoppt sein.')
                ->visible(fn (): bool => $this->supportsCrossplay())
                ->schema([
                    Select::make('bedrock_allocation_id')
                        ->label('Bedrock-Port Allocation')
                        ->options(fn (): array => $this->bedrockAllocationOptions()),
                ]),
        ];
    }

    public function save(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            app(MinecraftServerStateService::class)->assertOffline($server);
            $data = $this->form->getState();
            $setup = MinecraftToolkitSetup::query()
                ->where('server_uuid', $server->uuid)
                ->firstOrFail();
            $changes = $setup->software === 'bedrock'
                ? [
                    'server-name' => $data['motd'],
                    'max-players' => (int) $data['max_players'],
                    'view-distance' => (int) $data['view_distance'],
                    'tick-distance' => max(4, min(12, (int) $data['simulation_distance'])),
                    'online-mode' => (bool) $data['online_mode'],
                    'allow-list' => (bool) $data['whitelist'],
                ]
                : [
                    'motd' => $data['motd'],
                    'max-players' => (int) $data['max_players'],
                    'view-distance' => (int) $data['view_distance'],
                    'simulation-distance' => (int) $data['simulation_distance'],
                    'online-mode' => (bool) $data['online_mode'],
                    'white-list' => (bool) $data['whitelist'],
                    'pvp' => (bool) $data['pvp'],
                    'allow-flight' => (bool) $data['allow_flight'],
                ];
            $files = app(MinecraftServerFileService::class);
            $current = $files->read($server, '/server.properties', 1048576);
            $files->write($server, '/server.properties', app(MinecraftPropertiesService::class)->patch($current, $changes));
            $setup->fill(collect($data)->except('bedrock_allocation_id')->all())->save();

            Notification::make()->title('Minecraft-Einstellungen gespeichert')->success()->send();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title('Einstellungen konnten nicht gespeichert werden')
                ->body($exception instanceof MinecraftToolkitException
                    ? $exception->getMessage()
                    : 'Wings oder die Serverdatei ist derzeit nicht erreichbar.')
                ->danger()
                ->send();
        }
    }

    public function installCrossplay(): void
    {
        try {
            $data = $this->form->getState();
            $configured = app(MinecraftCrossplayService::class)->install(
                $this->server(),
                $this->setup(),
                (int) ($data['bedrock_allocation_id'] ?? 0)
            );

            Notification::make()
                ->title('Crossplay wurde installiert')
                ->body($configured
                    ? 'Geyser verwendet Floodgate und den ausgewählten Bedrock-Port.'
                    : 'Starte den Server einmal und klicke danach auf „Crossplay-Konfiguration anwenden“.')
                ->success()
                ->persistent(!$configured)
                ->send();
        } catch (MinecraftToolkitException $exception) {
            $this->crossplayError($exception);
        }
    }

    public function applyCrossplayConfig(): void
    {
        try {
            app(MinecraftCrossplayService::class)->applyConfig($this->server(), $this->setup());
            Notification::make()
                ->title('Crossplay-Konfiguration angewendet')
                ->body('Geyser verwendet jetzt Floodgate und den ausgewählten Bedrock-Port.')
                ->success()
                ->send();
        } catch (MinecraftToolkitException $exception) {
            $this->crossplayError($exception);
        }
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function setup(): MinecraftToolkitSetup
    {
        return MinecraftToolkitSetup::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('setup_status', 'completed')
            ->firstOrFail();
    }

    public function currentSetup(): MinecraftToolkitSetup
    {
        return $this->setup();
    }

    public function supportsCrossplay(): bool
    {
        return (bool) config('minecrafttoolkit.crossplay_enabled', true)
            && in_array($this->setup()->software, ['paper', 'purpur'], true);
    }

    /** @return array<int, string> */
    private function bedrockAllocationOptions(): array
    {
        $server = $this->server();

        $allocations = $server->allocations()
            ->where('id', '!=', $server->allocation_id)
            ->where('node_id', $server->node_id)
            ->get()
            ->mapWithKeys(fn ($allocation): array => [$allocation->id => $allocation->address])
            ->all();

        if (!(bool) config('minecrafttoolkit.bedrock_port_required', true) && $server->allocation) {
            return [$server->allocation->id => $server->allocation->address . ' (geteilt)'] + $allocations;
        }

        return $allocations;
    }

    private function currentBedrockAllocationId(Server $server, MinecraftToolkitSetup $setup): ?int
    {
        if (!$setup->bedrock_allocation_port) {
            return null;
        }

        return $server->allocations()
            ->where('port', $setup->bedrock_allocation_port)
            ->where('ip', $setup->bedrock_allocation_ip)
            ->value('id');
    }

    private function crossplayError(MinecraftToolkitException $exception): void
    {
        Notification::make()
            ->title('Crossplay-Aktion fehlgeschlagen')
            ->body($exception->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
