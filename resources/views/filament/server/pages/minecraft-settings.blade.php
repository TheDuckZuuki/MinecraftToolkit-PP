<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="tabler-device-floppy">
                Änderungen speichern
            </x-filament::button>
        </div>
    </form>

    @if ($this->supportsCrossplay())
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="installCrossplay" icon="tabler-device-gamepad-2">
                Crossplay installieren oder aktualisieren
            </x-filament::button>
            @if ($this->currentSetup()->crossplay_enabled)
                <x-filament::button wire:click="applyCrossplayConfig" color="gray" icon="tabler-file-settings">
                    Crossplay-Konfiguration anwenden
                </x-filament::button>
            @endif
        </div>
    @endif
</x-filament-panels::page>
