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

    public int $resultPage = 0;

    public string $resultsTitle = '';

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->form->fill([
            'source' => array_key_first($this->usableSourceOptions()),
            'query' => '',
        ]);
        $this->refreshInstalledPackages();
        $this->loadFeatured();
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
        return trans('minecrafttoolkit::strings.navigation.installer');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.installer');
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make(trans('minecrafttoolkit::strings.installer.package_source'))
                ->description(trans('minecrafttoolkit::strings.installer.search_help'))
                ->schema([
                    Select::make('source')
                        ->label(trans('minecrafttoolkit::strings.setup.source'))
                        ->options($this->sourceOptions())
                        ->disableOptionWhen(fn (string $value): bool => $value === 'curseforge'
                            && !$this->curseForgeConfigured())
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('query', '');
                            $this->resultPage = 0;
                            $this->candidate = null;
                            $this->loadFeatured();
                        })
                        ->required(),
                    TextInput::make('query')
                        ->label(trans('minecrafttoolkit::strings.installer.search', ['package' => $this->packageLabel()]))
                        ->placeholder($this->setup()->software === 'fabric'
                            ? trans('minecrafttoolkit::strings.installer.fabric_placeholder')
                            : trans('minecrafttoolkit::strings.installer.project_placeholder'))
                        ->maxLength(100)
                        ->helperText(trans('minecrafttoolkit::strings.installer.search_help')),
                ]),
        ];
    }

    public function search(): void
    {
        $this->resultPage = 0;
        $this->runSearch();
    }

    public function loadFeatured(): void
    {
        $this->resultPage = 0;
        $this->runSearch(true);
    }

    public function nextResultsPage(): void
    {
        $this->resultPage++;
        $this->runSearch(trim((string) ($this->data['query'] ?? '')) === '');
    }

    public function previousResultsPage(): void
    {
        $this->resultPage = max(0, $this->resultPage - 1);
        $this->runSearch(trim((string) ($this->data['query'] ?? '')) === '');
    }

    private function runSearch(bool $forcePopular = false): void
    {
        try {
            $query = trim((string) ($this->form->getState()['query'] ?? ''));
            $source = $this->selectedSource();
            $this->candidate = null;
            $offset = $this->resultPage * 20;
            $popular = $forcePopular || $query === '';
            $this->resultsTitle = $popular
                ? trans('minecrafttoolkit::strings.installer.featured') . ' - ' . $this->packageLabel(true)
                : 'Search: “' . $query . '”';

            $this->results = match ($source) {
                'modrinth' => $popular
                    ? app(ModrinthService::class)->popularPackages($this->setup(), $offset)
                    : app(ModrinthService::class)->searchPackages($query, $this->setup()),
                'curseforge' => $popular
                    ? app(CurseForgeService::class)->popularPackages($this->setup(), $offset)
                    : app(CurseForgeService::class)->searchPackages($query, $this->setup()),
                default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.installer.invalid_source')),
            };

            if ($this->results === []) {
                Notification::make()
                    ->title(trans('minecrafttoolkit::strings.installer.none_found', ['packages' => $this->packageLabel(true)]))
                    ->warning()
                    ->send();
            }
        } catch (MinecraftToolkitException $exception) {
            $this->results = [];
            $this->notifyError(trans('minecrafttoolkit::strings.installer.search_failed', ['source' => $this->sourceLabel()]), $exception);
        }
    }

    public function selectProject(string $projectId): void
    {
        try {
            $source = $this->selectedSource();
            $candidate = match ($source) {
                'modrinth' => app(ModrinthService::class)->installationCandidate($projectId, $this->setup()),
                'curseforge' => app(CurseForgeService::class)->installationCandidate($projectId, $this->setup()),
                default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.installer.invalid_source')),
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
            $this->notifyError(trans('minecrafttoolkit::strings.installer.check_failed', ['package' => $this->packageLabel()]), $exception);
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
                trans('minecrafttoolkit::strings.installer.invalid_selection'),
                new MinecraftToolkitException(trans('minecrafttoolkit::strings.installer.invalid_selection_body'))
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
                default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.installer.invalid_source')),
            };

            Notification::make()
                ->title(trans('minecrafttoolkit::strings.installer.installed_title', ['name' => $package->project_name]))
                ->body(trans('minecrafttoolkit::strings.installer.written_to_with_dependencies', ['directory' => $this->packageDirectory()]))
                ->success()
                ->send();

            $this->candidate = null;
            $this->refreshInstalledPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.installer.install_failed', ['package' => $this->packageLabel()]), $exception);
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
            ? ($plural ? trans('minecrafttoolkit::strings.installer.plugins') : trans('minecrafttoolkit::strings.installer.plugin'))
            : ($plural ? trans('minecrafttoolkit::strings.installer.mods') : trans('minecrafttoolkit::strings.installer.mod'));
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
        if ((bool) config('minecrafttoolkit.curseforge_enabled', false)
        ) {
            $options['curseforge'] = $this->curseForgeConfigured()
                ? 'CurseForge'
                : trans('minecrafttoolkit::strings.installer.proxy_missing_label');
        }

        return $options;
    }

    public function sourceLabel(): string
    {
        return $this->sourceOptions()[$this->selectedSource()] ?? trans('minecrafttoolkit::strings.installer.package_source');
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
            || (bool) config('minecrafttoolkit.curseforge_enabled', false);
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
