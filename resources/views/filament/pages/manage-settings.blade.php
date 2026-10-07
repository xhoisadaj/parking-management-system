<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" size="lg">
                Ruaj cilësimet
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
