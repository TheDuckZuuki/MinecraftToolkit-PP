<x-filament-panels::page>
    @if ($setup)
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500">Software</div>
                <div class="mt-1 text-lg font-semibold">{{ ucfirst($setup->software) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Minecraft-Version</div>
                <div class="mt-1 text-lg font-semibold">{{ $setup->minecraft_version }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Primärer Port</div>
                <div class="mt-1 text-lg font-semibold">{{ $setup->primary_allocation_port }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Verwaltete Pakete</div>
                <div class="mt-1 text-lg font-semibold">{{ $packageCount }}</div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Konfiguration">
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-gray-500">MOTD</dt><dd>{{ $setup->motd }}</dd></div>
                <div><dt class="text-sm text-gray-500">Welt</dt><dd>{{ $setup->level_name }}</dd></div>
                @if ($setup->loader_version)
                    <div><dt class="text-sm text-gray-500">Loader</dt><dd>{{ ucfirst($setup->loader) }} {{ $setup->loader_version }}</dd></div>
                @endif
                <div><dt class="text-sm text-gray-500">Maximale Spieler</dt><dd>{{ $setup->max_players }}</dd></div>
                <div><dt class="text-sm text-gray-500">Online Mode</dt><dd>{{ $setup->online_mode ? 'Aktiv' : 'Inaktiv' }}</dd></div>
                <div><dt class="text-sm text-gray-500">Crossplay</dt><dd>{{ $setup->crossplay_enabled ? 'Aktiv' : 'Inaktiv' }}</dd></div>
                @if ($setup->crossplay_enabled)
                    <div><dt class="text-sm text-gray-500">Bedrock-Port</dt><dd>{{ $setup->bedrock_allocation_port }}</dd></div>
                @endif
            </dl>
        </x-filament::section>

        <x-filament::section heading="Letzte Aktionen">
            <div class="space-y-3">
                @forelse ($logs as $entry)
                    <div>
                        <div class="font-medium">{{ $entry->message }}</div>
                        <div class="text-xs text-gray-500">{{ $entry->created_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Noch keine Aktionen protokolliert.</p>
                @endforelse
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
