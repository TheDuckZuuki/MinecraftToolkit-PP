<x-filament-panels::page>
    @if ($setup)
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.software') }}</div>
                <div class="mt-1 text-lg font-semibold">{{ ucfirst($setup->software) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.minecraft_version') }}</div>
                <div class="mt-1 text-lg font-semibold">{{ $setup->minecraft_version }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.primary_port') }}</div>
                <div class="mt-1 text-lg font-semibold">{{ $setup->primary_allocation_port }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.managed_packages') }}</div>
                <div class="mt-1 text-lg font-semibold">{{ $packageCount }}</div>
            </x-filament::section>
        </div>

        <x-filament::section :heading="trans('minecrafttoolkit::strings.overview.configuration')">
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-gray-500">MOTD</dt><dd>{{ $setup->motd }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.world') }}</dt><dd>{{ $setup->level_name }}</dd></div>
                @if ($setup->loader_version)
                    <div><dt class="text-sm text-gray-500">Loader</dt><dd>{{ ucfirst($setup->loader) }} {{ $setup->loader_version }}</dd></div>
                @endif
                <div><dt class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.max_players') }}</dt><dd>{{ $setup->max_players }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.online_mode') }}</dt><dd>{{ $setup->online_mode ? trans('minecrafttoolkit::strings.overview.active') : trans('minecrafttoolkit::strings.overview.inactive') }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.overview.crossplay') }}</dt><dd>{{ $setup->crossplay_enabled ? trans('minecrafttoolkit::strings.overview.active') : trans('minecrafttoolkit::strings.overview.inactive') }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="trans('minecrafttoolkit::strings.overview.source_status')">
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ($sourceStatuses as $source)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ $source['name'] }}</span>
                            <span class="{{ $source['enabled'] ? 'text-success-600' : 'text-gray-500' }}">
                                {{ $source['enabled'] ? trans('minecrafttoolkit::strings.overview.active') : trans('minecrafttoolkit::strings.overview.inactive') }}
                            </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">{{ $source['detail'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="trans('minecrafttoolkit::strings.overview.latest_logs')">
            <div class="space-y-2">
                @forelse ($logs as $log)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">{{ $log->message }}</div>
                @empty
                    <p class="text-sm text-gray-500">-</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section :heading="trans('minecrafttoolkit::strings.overview.backups')">
            <div class="space-y-2">
                @forelse ($backups as $backup)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                        <div class="font-medium">{{ $backup['created'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $backup['path'] }}</div>
                        <div class="mt-2 text-gray-600 dark:text-gray-300">
                            @forelse ($backup['files'] as $file)
                                <span class="mr-2">{{ $file['name'] }}</span>
                            @empty
                                <span>{{ trans('minecrafttoolkit::strings.overview.backup_files_unavailable') }}</span>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">-</p>
                @endforelse
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
