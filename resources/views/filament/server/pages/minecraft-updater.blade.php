<x-filament-panels::page>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="checkUpdates" icon="tabler-search" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.updater.check_updates') }}</x-filament::button>
        <x-filament::button wire:click="updateAll" icon="tabler-refresh" color="warning" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.updater.update_all') }}</x-filament::button>
    </div>
    <x-filament::section :heading="trans('minecrafttoolkit::strings.updater.managed_packages')">
        <div class="space-y-3">
            @forelse ($packages as $package)
                <div class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><span class="font-semibold">{{ $package['name'] }}</span><span class="text-xs uppercase text-gray-500">{{ $package['type'] }}</span>@if ($package['system'])<span class="text-xs text-primary-600">{{ trans('minecrafttoolkit::strings.updater.system_package') }}</span>@endif</div>
                        <div class="mt-1 text-sm text-gray-500">{{ ucfirst($package['source']) }} · {{ trans('minecrafttoolkit::strings.updater.installed') }}: {{ $package['current_version'] ?: trans('minecrafttoolkit::strings.updater.unknown') }} @if ($package['status'] === 'update_available') · {{ trans('minecrafttoolkit::strings.updater.available') }}: {{ $package['new_version'] }} @endif</div>
                        <div class="mt-1 text-sm">@switch($package['status'])@case('up_to_date')<span class="text-success-600">{{ trans('minecrafttoolkit::strings.updater.up_to_date') }}</span>@break @case('update_available')<span class="text-warning-600">{{ trans('minecrafttoolkit::strings.updater.update_available') }}</span>@break @case('error')<span class="text-danger-600">{{ $package['message'] }}</span>@break @default<span class="text-gray-500">{{ trans('minecrafttoolkit::strings.updater.not_checked') }}</span>@endswitch @if ($package['checked_at'])<span class="text-xs text-gray-500"> · {{ $package['checked_at'] }}</span>@endif</div>
                    </div>
                    <div class="flex flex-wrap gap-2 md:justify-end">
                        @if ($package['status'] === 'update_available')<x-filament::button size="sm" wire:click="updatePackage({{ $package['id'] }})" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.updater.update') }}</x-filament::button>@endif
                        @if ($package['can_install_dependencies'])<x-filament::button size="sm" color="info" wire:click="installDependencies({{ $package['id'] }})" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.updater.install_dependencies') }}</x-filament::button>@endif
                        @if ($package['can_delete'])<x-filament::button size="sm" color="danger" wire:click="deletePackage({{ $package['id'] }})" wire:confirm="{{ trans('minecrafttoolkit::strings.updater.delete_confirm') }}" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.updater.delete') }}</x-filament::button>@endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.updater.empty') }}</p>
            @endforelse
        </div>
    </x-filament::section>
    <x-filament::section :heading="trans('minecrafttoolkit::strings.updater.history')">
        <div class="space-y-3">
            @forelse ($history as $entry)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">{{ $entry['package'] }}</span><span class="text-xs text-gray-500">{{ $entry['checked_at'] }}</span></div><div class="mt-1 text-sm text-gray-500">{{ $entry['old_version'] ?: trans('minecrafttoolkit::strings.updater.unknown') }} @if ($entry['new_version']) → {{ $entry['new_version'] }} @endif · {{ $entry['message'] }}</div></div>
            @empty
                <p class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.updater.no_history') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
