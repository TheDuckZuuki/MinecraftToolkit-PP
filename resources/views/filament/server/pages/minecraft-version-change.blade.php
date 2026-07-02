<x-filament-panels::page>
    <form wire:submit="checkCompatibility">
        {{ $this->form }}
        <div class="mt-4"><x-filament::button type="submit" icon="tabler-shield-check" wire:loading.attr="disabled">{{ trans('minecrafttoolkit::strings.version_change.check') }}</x-filament::button></div>
    </form>
    @if ($report)
        <x-filament::section :heading="trans('minecrafttoolkit::strings.version_change.compatibility')">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <div><div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.version_change.target_version') }}</div><div class="font-semibold">{{ $report['target']['minecraft_version'] }}</div></div>
                <div><div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.version_change.package_updates') }}</div><div class="font-semibold">{{ $report['updates'] }}</div></div>
                <div><div class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.version_change.blocking_packages') }}</div><div class="font-semibold">{{ $report['blocking'] }}</div></div>
            </div>
            <div class="space-y-3">
                @forelse ($report['packages'] as $package)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">{{ $package['name'] }}</span><span class="text-sm">@switch($package['status'])@case('compatible')<span class="text-success-600">{{ trans('minecrafttoolkit::strings.version_change.compatible') }}</span>@break @case('update_required')<span class="text-warning-600">{{ trans('minecrafttoolkit::strings.version_change.update_required') }}</span>@break @case('system_update')<span class="text-primary-600">{{ trans('minecrafttoolkit::strings.version_change.system_update') }}</span>@break @case('pinned')<span class="text-warning-600">{{ trans('minecrafttoolkit::strings.version_change.pinned') }}</span>@break @case('incompatible')<span class="text-danger-600">{{ trans('minecrafttoolkit::strings.version_change.incompatible') }}</span>@break @default<span class="text-gray-500">{{ trans('minecrafttoolkit::strings.version_change.unknown') }}</span>@endswitch</span></div>
                        <div class="mt-1 text-sm text-gray-500">{{ $package['current_version'] ?: trans('minecrafttoolkit::strings.version_change.unknown_version') }} @if ($package['target_version']) → {{ $package['target_version'] }} @endif · {{ $package['message'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ trans('minecrafttoolkit::strings.version_change.no_packages') }}</p>
                @endforelse
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                @if ($report['blocking'] === 0)
                    <x-filament::button wire:click="changeVersion('safe')" icon="tabler-switch-horizontal">{{ trans('minecrafttoolkit::strings.version_change.safe_change') }}</x-filament::button>
                @else
                    <x-filament::button wire:click="changeVersion('remove')" color="warning" icon="tabler-package-off">{{ trans('minecrafttoolkit::strings.version_change.remove_change') }}</x-filament::button>
                    <x-filament::button wire:click="changeVersion('risk')" color="danger" icon="tabler-alert-triangle">{{ trans('minecrafttoolkit::strings.version_change.risk_change') }}</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
