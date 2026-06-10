<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftPackageInstaller;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\ModrinthService;
use BlueWolf\MinecraftToolkit\Services\CurseForgeService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * @property \Filament\Schemas\Schema $form
 */
class MinecraftInstallerPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-package-import';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'minecraft-installer';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-installer';

    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /** @var array<string, mixed>|null */
    public ?array $candidate = null;

    /** @var array<int, array<string, mixed>> */
    public array $installedPackages = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->form->fill([
            'source' => array_key_first($this->usableSourceOptions()),
            'query' => '',
        ]);
        $this->refreshInstalledPackages();
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('minecrafttoolkit.enabled', true)
            || !static::hasEnabledSource()
            || !Schema::hasTable('minecraft_toolkit_setups')
            || !Schema::hasTable('minecraft_toolkit_packages')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();
        if (!$server instanceof Server
            || $user === null
            || !app(MinecraftPermissionService::class)->canModify($user, $server)) {
            return false;
        }

        return MinecraftToolkitSetup::query()
            ->where('server_uuid', $server->uuid)
            ->where('setup_status', 'completed')
            ->whereIn('software', ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'])
            ->exists();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return 'Minecraft Installer';
    }

    public function getTitle(): string
    {
        return 'Minecraft Installer';
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Paketquelle durchsuchen')
                ->description(fn (): string => $this->selectedSource() === 'curseforge'
                    ? sprintf(
                        'Es werden kompatible %s für Minecraft %s und den Loader %s angezeigt. Prüfe bei Mods die Projektbeschreibung auf Server-Kompatibilität.',
                        $this->packageLabel(true),
                        $this->setup()->minecraft_version,
                        ucfirst($this->setup()->software)
                    )
                    : sprintf(
                        'Es werden nur serverseitige %s für Minecraft %s und den Loader %s angezeigt.',
                        $this->packageLabel(true),
                        $this->setup()->minecraft_version,
                        ucfirst($this->setup()->software)
                    ))
                ->schema([
                    Select::make('source')
                        ->label('Quelle')
                        ->options($this->sourceOptions())
                        ->disableOptionWhen(fn (string $value): bool => $value === 'curseforge'
                            && !$this->curseForgeConfigured())
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('query', '');
                            $this->results = [];
                            $this->candidate = null;
                        })
                        ->required(),
                    TextInput::make('query')
                        ->label($this->packageLabel() . ' suchen')
                        ->placeholder($this->setup()->software === 'fabric'
                            ? 'Zum Beispiel Fabric API, Lithium oder Simple Voice Chat'
                            : 'Projektname')
                        ->minLength(2)
                        ->maxLength(100)
                        ->required(),
                ]),
        ];
    }

    public function search(): void
    {
        try {
            $query = (string) ($this->form->getState()['query'] ?? '');
            $source = $this->selectedSource();
            $this->candidate = null;
            $this->results = match ($source) {
                'modrinth' => app(ModrinthService::class)->searchPackages($query, $this->setup()),
                'curseforge' => app(CurseForgeService::class)->searchPackages($query, $this->setup()),
                default => throw new MinecraftToolkitException('Wähle eine gültige Paketquelle.'),
            };

            if ($this->results === []) {
                Notification::make()
                    ->title('Keine kompatiblen ' . $this->packageLabel(true) . ' gefunden')
                    ->warning()
                    ->send();
            }
        } catch (MinecraftToolkitException $exception) {
            $this->results = [];
            $this->notifyError($this->sourceLabel() . '-Suche fehlgeschlagen', $exception);
        }
    }

    public function selectProject(string $projectId): void
    {
        try {
            $source = $this->selectedSource();
            $candidate = match ($source) {
                'modrinth' => app(ModrinthService::class)->installationCandidate($projectId, $this->setup()),
                'curseforge' => app(CurseForgeService::class)->installationCandidate($projectId, $this->setup()),
                default => throw new MinecraftToolkitException('Wähle eine gültige Paketquelle.'),
            };
            $candidate['source'] = $source;
            $installedProjectIds = MinecraftToolkitPackage::query()
                ->where('server_uuid', $this->server()->uuid)
                ->where('source', $source)
                ->where('managed', true)
                ->pluck('source_project_id')
                ->all();

            $candidate['dependencies'] = collect($candidate['dependencies'])
                ->map(function (array $dependency) use ($installedProjectIds): array {
                    $dependency['installed'] = is_string($dependency['project_id'])
                        && in_array($dependency['project_id'], $installedProjectIds, true);

                    return $dependency;
                })
                ->all();
            $this->candidate = $candidate;
        } catch (MinecraftToolkitException $exception) {
            $this->candidate = null;
            $this->notifyError($this->packageLabel() . ' konnte nicht geprüft werden', $exception);
        }
    }

    public function clearSelection(): void
    {
        $this->candidate = null;
    }

    public function installSelected(): void
    {
        $projectId = $this->candidate['project']['project_id'] ?? null;
        if (!is_string($projectId)) {
            $this->notifyError(
                'Installation nicht möglich',
                new MinecraftToolkitException('Es wurde kein gültiges Paket ausgewählt.')
            );

            return;
        }

        try {
            $source = (string) ($this->candidate['source'] ?? $this->selectedSource());
            $package = match ($source) {
                'modrinth' => app(MinecraftPackageInstaller::class)
                    ->installModrinthPackage($this->server(), $this->setup(), $projectId),
                'curseforge' => app(MinecraftPackageInstaller::class)
                    ->installCurseForgePackage($this->server(), $this->setup(), $projectId),
                default => throw new MinecraftToolkitException('Wähle eine gültige Paketquelle.'),
            };

            $requiredMissing = collect($this->candidate['dependencies'] ?? [])
                ->where('type', 'required')
                ->where('installed', false)
                ->count();

            Notification::make()
                ->title("{$package->project_name} wurde installiert")
                ->body($requiredMissing > 0
                    ? "$requiredMissing erforderliche Dependencies sind noch nicht installiert. Prüfe und installiere sie separat über die Suche."
                    : "Die JAR wurde über Wings nach /{$this->packageDirectory()} geschrieben.")
                ->success()
                ->persistent($requiredMissing > 0)
                ->send();

            $this->candidate = null;
            $this->refreshInstalledPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError($this->packageLabel() . '-Installation fehlgeschlagen', $exception);
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

    private function refreshInstalledPackages(): void
    {
        $this->installedPackages = MinecraftToolkitPackage::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('package_type', in_array($this->setup()->software, ['paper', 'purpur', 'folia'], true)
                ? 'plugin'
                : 'mod')
            ->latest('installed_at')
            ->get()
            ->map(fn (MinecraftToolkitPackage $package): array => [
                'id' => $package->id,
                'project_name' => $package->project_name,
                'version_number' => $package->version_number,
                'file_name' => $package->file_name,
                'source' => $package->source,
                'managed' => $package->managed,
                'installed_at' => $package->installed_at?->diffForHumans(),
            ])
            ->all();
    }

    public function packageLabel(bool $plural = false): string
    {
        $isPlugin = in_array($this->setup()->software, ['paper', 'purpur', 'folia'], true);

        return $isPlugin
            ? ($plural ? 'Plugins' : 'Plugin')
            : ($plural ? 'Mods' : 'Mod');
    }

    public function packageDirectory(): string
    {
        return in_array($this->setup()->software, ['paper', 'purpur', 'folia'], true) ? 'plugins' : 'mods';
    }

    /** @return array<string, string> */
    public function sourceOptions(): array
    {
        $options = [];
        if ((bool) config('minecrafttoolkit.modrinth_enabled', true)) {
            $options['modrinth'] = 'Modrinth';
        }
        if ((bool) config('minecrafttoolkit.curseforge_enabled', true)
        ) {
            $options['curseforge'] = $this->curseForgeConfigured()
                ? 'CurseForge'
                : 'CurseForge (Proxy/API-Key fehlt)';
        }

        return $options;
    }

    public function sourceLabel(): string
    {
        return $this->sourceOptions()[$this->selectedSource()] ?? 'Paketquelle';
    }

    public function curseForgeConfigured(): bool
    {
        return app(CurseForgeService::class)->isConfigured();
    }

    public function hasUsableSource(): bool
    {
        return $this->usableSourceOptions() !== [];
    }

    /** @return array<string, string> */
    private function usableSourceOptions(): array
    {
        return collect($this->sourceOptions())
            ->reject(fn (string $label, string $source): bool => $source === 'curseforge'
                && !$this->curseForgeConfigured())
            ->all();
    }

    private function selectedSource(): string
    {
        return (string) ($this->data['source'] ?? array_key_first($this->sourceOptions()) ?? '');
    }

    private static function hasEnabledSource(): bool
    {
        return (bool) config('minecrafttoolkit.modrinth_enabled', true)
            || (bool) config('minecrafttoolkit.curseforge_enabled', true);
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
}
