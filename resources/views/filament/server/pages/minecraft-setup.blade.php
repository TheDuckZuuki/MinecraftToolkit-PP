<x-filament-panels::page>
    <form wire:submit="setup">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="tabler-player-play">
                Setup starten
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
