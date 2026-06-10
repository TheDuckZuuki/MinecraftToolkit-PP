<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftCompatibilityService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftSoftwareService;
use BlueWolf\MinecraftToolkit\Services\MinecraftVersionChangeService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * @property \Filament\Schemas\Schema $form
 */
class MinecraftVersionChangePage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-switch-horizontal';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 23;

    protected static ?string $slug = 'minecraft-version-change';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-version-change';

    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->authorizeAccess();
        $setup = $this->setup();
        $this->form->fill([
            'minecraft_version' => null,
            'loader_version' => null,
            'confirm_remove' => false,
            'confirm_risk' => false,
            'current_version' => $setup->minecraft_version,
            'current_loader_version' => $setup->loader_version,
        ]);
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('minecrafttoolkit.enabled', true)
            || !(bool) config('minecrafttoolkit.version_change_enabled', true)
            || !Schema::hasTable('minecraft_toolkit_setups')
            || !Schema::hasTable('minecraft_toolkit_packages')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();
        if ($user !== null
            && !(bool) config('minecrafttoolkit.version_change_users_enabled', true)
            && !$user->isRootAdmin()) {
            return false;
        }

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
        return 'Minecraft-Version ändern';
    }

    public function getTitle(): string
    {
        return 'Minecraft-Version ändern';
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Zielversion')
                ->description(fn (): string => sprintf(
                    'Aktuell: %s %s%s',
                    ucfirst($this->setup()->software),
                    $this->setup()->minecraft_version,
                    $this->setup()->loader_version ? " / Loader {$this->setup()->loader_version}" : ''
                ))
                ->columns(2)
                ->schema([
                    Select::make('minecraft_version')
                        ->label('Neue Minecraft-Version')
                        ->options(fn (): array => app(MinecraftSoftwareService::class)
                            ->versionOptions($this->setup()->software))
                        ->disableOptionWhen(fn (string $value): bool => $value === $this->setup()->minecraft_version)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('loader_version', null);
                            $set('confirm_remove', false);
                            $set('confirm_risk', false);
                            $this->report = null;
                        })
                        ->required(),
                    Select::make('loader_version')
                        ->label('Neue Loader-Version')
                        ->options(fn (Get $get): array => app(MinecraftSoftwareService::class)->loaderVersionOptions(
                            $this->setup()->software,
                            (string) $get('minecraft_version')
                        ))
                        ->visible(fn (): bool => in_array(
                            $this->setup()->software,
                            ['fabric', 'forge', 'neoforge'],
                            true
                        ))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('confirm_remove', false);
                            $set('confirm_risk', false);
                            $this->report = null;
                        })
                        ->required(fn (): bool => in_array(
                            $this->setup()->software,
                            ['fabric', 'forge', 'neoforge'],
                            true
                        )),
                    Toggle::make('confirm_remove')
                        ->label('Inkompatible und unbekannte Pakete sichern und deaktivieren')
                        ->visible(fn (): bool => ($this->report['blocking'] ?? 0) > 0),
                    Toggle::make('confirm_risk')
                        ->label('Ich akzeptiere, dass der Server danach möglicherweise nicht startet')
                        ->visible(fn (): bool => ($this->report['blocking'] ?? 0) > 0),
                ]),
        ];
    }

    public function checkCompatibility(): void
    {
        try {
            $state = $this->form->getState();
            $this->report = app(MinecraftCompatibilityService::class)->check(
                $this->server(),
                $this->setup(),
                (string) $state['minecraft_version'],
                is_string($state['loader_version'] ?? null) ? $state['loader_version'] : null
            );
            Notification::make()
                ->title('Kompatibilitätsprüfung abgeschlossen')
                ->body(($this->report['blocking'] ?? 0) > 0
                    ? "{$this->report['blocking']} Pakete blockieren einen sicheren Wechsel."
                    : 'Für alle verwalteten Pakete wurde eine kompatible Strategie gefunden.')
                ->status(($this->report['blocking'] ?? 0) > 0 ? 'warning' : 'success')
                ->send();
        } catch (MinecraftToolkitException $exception) {
            $this->report = null;
            $this->notifyError('Kompatibilitätsprüfung fehlgeschlagen', $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->report = null;
            $this->notifyUnexpectedError('Kompatibilitätsprüfung fehlgeschlagen');
        }
    }

    public function changeVersion(string $mode): void
    {
        if ($this->report === null) {
            $this->notifyError(
                'Versionswechsel nicht möglich',
                new MinecraftToolkitException('Prüfe zuerst die Paketkompatibilität.')
            );

            return;
        }

        $state = $this->form->getState();
        if ($mode === 'remove' && !($state['confirm_remove'] ?? false)) {
            $this->notifyError(
                'Bestätigung fehlt',
                new MinecraftToolkitException('Bestätige zuerst das Sichern und Deaktivieren inkompatibler Pakete.')
            );

            return;
        }
        if ($mode === 'risk' && !($state['confirm_risk'] ?? false)) {
            $this->notifyError(
                'Bestätigung fehlt',
                new MinecraftToolkitException('Bestätige ausdrücklich das Risiko dieses Versionswechsels.')
            );

            return;
        }

        try {
            $result = app(MinecraftVersionChangeService::class)->change(
                $this->server(),
                $this->setup(),
                (string) $state['minecraft_version'],
                is_string($state['loader_version'] ?? null) ? $state['loader_version'] : null,
                $mode
            );

            Notification::make()
                ->title("Minecraft {$result['setup']->minecraft_version} wurde installiert")
                ->body("Pakete aktualisiert: {$result['updated']}, gesichert: {$result['removed']}, fehlgeschlagen: {$result['failed']}")
                ->status($result['failed'] > 0 ? 'warning' : 'success')
                ->persistent($result['failed'] > 0)
                ->send();
            $this->redirect(static::getUrl(panel: 'server', tenant: $this->server()));
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError('Versionswechsel fehlgeschlagen', $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError('Versionswechsel fehlgeschlagen');
        }
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
            ->body('Eine externe Quelle oder Wings ist derzeit nicht erreichbar. Technische Details wurden protokolliert.')
            ->danger()
            ->persistent()
            ->send();
    }
}
