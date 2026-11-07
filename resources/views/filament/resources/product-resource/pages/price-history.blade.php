<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->filtersForm }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="applyFilters" wire:loading.attr="disabled">
                {{ __('Apply filters') }}
            </x-filament::button>
            <x-filament::button color="gray" wire:click="clearFilters" wire:loading.attr="disabled">
                {{ __('Clear filters') }}
            </x-filament::button>
        </div>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
