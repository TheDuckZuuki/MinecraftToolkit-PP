<x-filament-panels::page>
    @if (config('minecrafttoolkit.curseforge_enabled') && !$this->curseForgeConfigured())
        <x-filament::section heading="CurseForge ist nicht konfiguriert">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                CurseForge ist deaktiviert, weil dieser Plugin-Build keinen zentralen API-Key enthält und kein lokaler Override gesetzt wurde.
            </p>
        </x-filament::section>
    @endif

    <form wire:submit="search">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button
                type="submit"
                icon="tabler-search"
                :disabled="!$this->hasUsableSource()"
                wire:loading.attr="disabled"
            >
                {{ $this->sourceLabel() }} durchsuchen
            </x-filament::button>
        </div>
    </form>

    @if ($candidate)
        <x-filament::section heading="Installation prüfen">
            <div class="space-y-5">
                <div class="flex items-start gap-4">
                    @if ($candidate['project']['icon_url'])
                        <img
                            src="{{ $candidate['project']['icon_url'] }}"
                            alt=""
                            class="h-16 w-16 rounded-lg object-cover"
                        >
                    @endif
                    <div>
                        <h3 class="text-lg font-semibold">{{ $candidate['project']['title'] }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $candidate['project']['description'] }}</p>
                        <p class="mt-2 text-sm">
                            Version: <strong>{{ $candidate['version']['version_number'] }}</strong>
                            · Datei: <code>{{ $candidate['version']['selected_file']['filename'] }}</code>
                        </p>
                    </div>
                </div>

                @if ($candidate['warning'] ?? null)
                    <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                        {{ $candidate['warning'] }}
                    </div>
                @endif

                <div>
                    <h4 class="font-medium">Dependencies</h4>
                    <div class="mt-2 space-y-2">
                        @forelse ($candidate['dependencies'] as $dependency)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <div>
                                    <span class="font-medium">{{ $dependency['title'] }}</span>
                                    <span class="ml-2 text-xs uppercase text-gray-500">{{ $dependency['type'] }}</span>
                                </div>
                                <span class="text-sm {{ $dependency['installed'] ? 'text-success-600' : ($dependency['type'] === 'required' ? 'text-danger-600' : 'text-gray-500') }}">
                                    {{ $dependency['installed'] ? 'Installiert' : 'Nicht installiert' }}
                                </span>
                                @if (!$dependency['installed'] && $dependency['project_id'])
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        wire:click="selectProject('{{ $dependency['project_id'] }}')"
                                    >
                                        Dependency prüfen
                                    </x-filament::button>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Für diese Version meldet die Quelle keine Dependencies.</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex gap-3">
                    <x-filament::button wire:click="installSelected" icon="tabler-download" wire:loading.attr="disabled">
                        {{ $this->packageLabel() }} installieren
                    </x-filament::button>
                    <x-filament::button wire:click="clearSelection" color="gray">
                        Abbrechen
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($results !== [])
        <x-filament::section heading="Suchergebnisse">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($results as $result)
                    <div class="flex gap-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        @if ($result['icon_url'])
                            <img src="{{ $result['icon_url'] }}" alt="" class="h-14 w-14 rounded-lg object-cover">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold">{{ $result['title'] }}</h3>
                                    <p class="text-xs text-gray-500">
                                        {{ $result['author'] }} · {{ number_format($result['downloads']) }} Downloads
                                    </p>
                                </div>
                                <x-filament::button
                                    size="sm"
                                    wire:click="selectProject('{{ $result['project_id'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    Prüfen
                                </x-filament::button>
                            </div>
                            <p class="mt-2 line-clamp-2 text-sm text-gray-600 dark:text-gray-300">
                                {{ $result['description'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="'Installierte ' . $this->packageLabel(true)">
        <div class="space-y-3">
            @forelse ($installedPackages as $package)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div>
                        <div class="font-medium">{{ $package['project_name'] }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $package['version_number'] ?: 'Version unbekannt' }} · {{ $package['file_name'] }}
                        </div>
                    </div>
                    <div class="text-right text-xs text-gray-500">
                        <div>{{ ucfirst($package['source']) }}</div>
                        <div>{{ $package['installed_at'] }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Noch keine {{ $this->packageLabel(true) }} mit Minecraft Toolkit installiert.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
