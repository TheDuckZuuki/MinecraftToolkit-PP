@php
    $formData = $page->data ?? [];
    $software = $formData['software'] ?? null;
    $source = $formData['setup_package_source'] ?? 'modrinth';
@endphp

@if (in_array($software, ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'], true))
    <div class="space-y-4">
        <div class="flex flex-wrap gap-3">
            <x-filament::button type="button" icon="tabler-search" wire:click="searchSetupPackages" wire:loading.attr="disabled">
                {{ trans('minecrafttoolkit::strings.installer.browse_source', ['source' => ucfirst($source)]) }}
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="tabler-list" wire:click="showPopularSetupPackages" wire:loading.attr="disabled">
                {{ trans('minecrafttoolkit::strings.installer.show_popular') }}
            </x-filament::button>
        </div>

        @if ($page->selectedSetupPackageIds !== [])
            <div class="rounded-xl border border-primary-300 bg-primary-50 p-3 text-sm text-primary-800 dark:border-primary-700 dark:bg-primary-950 dark:text-primary-200">
                {{ trans('minecrafttoolkit::strings.setup.selected_packages', ['count' => count($page->selectedSetupPackageIds)]) }}
            </div>
        @endif

        @if ($page->setupPackageResults !== [])
            <div>
                <h3 class="mb-3 text-base font-semibold">{{ $page->setupPackageResultsTitle }}</h3>
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($page->setupPackageResults as $result)
                        @php($sourceProjectId = $source . ':' . $result['project_id'])
                        <div class="flex min-h-[96px] gap-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            @if (!empty($result['icon_url']))
                                <img src="{{ $result['icon_url'] }}" alt="" style="width: 56px; height: 56px; min-width: 56px; max-width: 56px; object-fit: cover; border-radius: 0.5rem;">
                            @else
                                <div style="width: 56px; height: 56px; min-width: 56px; max-width: 56px; border-radius: 0.5rem;" class="bg-gray-100 dark:bg-white/10"></div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="truncate font-semibold">{{ $result['title'] }}</h3>
                                        <p class="truncate text-xs text-gray-500">{{ $result['author'] }} · {{ number_format((int) ($result['downloads'] ?? 0)) }} {{ trans('minecrafttoolkit::strings.installer.downloads') }}</p>
                                    </div>
                                    <x-filament::button type="button" size="sm" :color="$page->setupPackageSelected($sourceProjectId) ? 'success' : 'primary'" wire:click="toggleSetupPackage('{{ $sourceProjectId }}')" wire:loading.attr="disabled">
                                        {{ $page->setupPackageSelected($sourceProjectId) ? trans('minecrafttoolkit::strings.setup.selected') : trans('minecrafttoolkit::strings.setup.select') }}
                                    </x-filament::button>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm text-gray-600 dark:text-gray-300">{{ $result['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <x-filament::button type="button" color="gray" size="sm" wire:click="previousSetupPackagePage" :disabled="$page->setupPackagePage === 0" wire:loading.attr="disabled">
                        {{ trans('minecrafttoolkit::strings.installer.previous') }}
                    </x-filament::button>
                    <span class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.installer.page', ['page' => $page->setupPackagePage + 1]) }}</span>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="nextSetupPackagePage" wire:loading.attr="disabled">
                        {{ trans('minecrafttoolkit::strings.installer.next') }}
                    </x-filament::button>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $page->setupPackageResultsTitle }}</p>
        @endif
    </div>
@endif
