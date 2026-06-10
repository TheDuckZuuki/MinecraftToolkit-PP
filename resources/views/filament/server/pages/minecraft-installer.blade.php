<x-filament-panels::page>
    @if (config('minecrafttoolkit.curseforge_enabled') && !$this->curseForgeConfigured())
        <x-filament::section :heading="trans('minecrafttoolkit::strings.installer.missing_proxy')">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ trans('minecrafttoolkit::strings.installer.missing_proxy_body') }}
            </p>
        </x-filament::section>
    @endif

    <form wire:submit="search">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="tabler-search" :disabled="!$this->hasUsableSource()" wire:loading.attr="disabled">
                {{ trans('minecrafttoolkit::strings.installer.browse_source', ['source' => $this->sourceLabel()]) }}
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="tabler-list" :disabled="!$this->hasUsableSource()" wire:click="loadFeatured" wire:loading.attr="disabled">
                {{ trans('minecrafttoolkit::strings.installer.show_popular') }}
            </x-filament::button>
        </div>
    </form>

    @if ($candidate)
        <x-filament::section :heading="trans('minecrafttoolkit::strings.installer.review_install')">
            <div class="space-y-5">
                <div class="flex items-start gap-4">
                    @if ($candidate['project']['icon_url'])
                        <img src="{{ $candidate['project']['icon_url'] }}" alt="" style="width: 64px; height: 64px; min-width: 64px; max-width: 64px; object-fit: cover; border-radius: 0.5rem;">
                    @endif
                    <div>
                        <h3 class="text-lg font-semibold">{{ $candidate['project']['title'] }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $candidate['project']['description'] }}</p>
                        <p class="mt-2 text-sm">
                            {{ trans('minecrafttoolkit::strings.installer.version') }}: <strong>{{ $candidate['version']['version_number'] }}</strong>
                            · {{ trans('minecrafttoolkit::strings.installer.file') }}: <code>{{ $candidate['version']['selected_file']['filename'] }}</code>
                        </p>
                    </div>
                </div>

                @if ($candidate['warning'] ?? null)
                    <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                        {{ $candidate['warning'] }}
                    </div>
                @endif

                <div>
                    <h4 class="font-medium">{{ trans('minecrafttoolkit::strings.installer.dependencies') }}</h4>
                    <div class="mt-2 space-y-2">
                        @forelse ($candidate['dependencies'] as $dependency)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <div>
                                    <span class="font-medium">{{ $dependency['title'] }}</span>
                                    <span class="ml-2 text-xs uppercase text-gray-500">{{ $dependency['type'] }}</span>
                                </div>
                                <span class="text-sm {{ $dependency['installed'] ? 'text-success-600' : ($dependency['type'] === 'required' ? 'text-danger-600' : 'text-gray-500') }}">
                                    {{ $dependency['installed'] ? trans('minecrafttoolkit::strings.installer.installed') : trans('minecrafttoolkit::strings.installer.not_installed') }}
                                </span>
                                @if (!$dependency['installed'] && $dependency['project_id'])
                                    <x-filament::button size="xs" color="gray" wire:click="selectProject('{{ $dependency['project_id'] }}')">
                                        {{ trans('minecrafttoolkit::strings.installer.check_dependency') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.installer.no_dependencies') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex gap-3">
                    <x-filament::button wire:click="installSelected" icon="tabler-download" wire:loading.attr="disabled">
                        {{ trans('minecrafttoolkit::strings.installer.install_package', ['package' => $this->packageLabel()]) }}
                    </x-filament::button>
                    <x-filament::button wire:click="clearSelection" color="gray">
                        {{ trans('minecrafttoolkit::strings.installer.cancel') }}
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($results !== [])
        <x-filament::section :heading="$resultsTitle">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($results as $result)
                    <div class="flex min-h-[96px] gap-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        @if ($result['icon_url'])
                            <img src="{{ $result['icon_url'] }}" alt="" style="width: 56px; height: 56px; min-width: 56px; max-width: 56px; object-fit: cover; border-radius: 0.5rem;">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold">{{ $result['title'] }}</h3>
                                    <p class="truncate text-xs text-gray-500">{{ $result['author'] }} · {{ number_format($result['downloads']) }} {{ trans('minecrafttoolkit::strings.installer.downloads') }}</p>
                                </div>
                                <x-filament::button size="sm" wire:click="selectProject('{{ $result['project_id'] }}')" wire:loading.attr="disabled">
                                    {{ trans('minecrafttoolkit::strings.installer.check') }}
                                </x-filament::button>
                            </div>
                            <p class="mt-2 line-clamp-2 text-sm text-gray-600 dark:text-gray-300">{{ $result['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
                <x-filament::button color="gray" size="sm" wire:click="previousResultsPage" :disabled="$resultPage === 0" wire:loading.attr="disabled">
                    {{ trans('minecrafttoolkit::strings.installer.previous') }}
                </x-filament::button>
                <span class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.installer.page', ['page' => $resultPage + 1]) }}</span>
                <x-filament::button color="gray" size="sm" wire:click="nextResultsPage" wire:loading.attr="disabled">
                    {{ trans('minecrafttoolkit::strings.installer.next') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="trans('minecrafttoolkit::strings.installer.installed_heading', ['packages' => $this->packageLabel(true)])">
        <div class="space-y-3">
            @forelse ($installedPackages as $package)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div>
                        <div class="font-medium">{{ $package['project_name'] }}</div>
                        <div class="text-sm text-gray-500">{{ ucfirst($package['source']) }} · {{ $package['version_number'] ?: '—' }} · {{ $package['file_name'] }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.installer.no_installed') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
