@php
    $searchLogKey = $searchQuery.':'.count($progressLog).':'.data_get(collect($progressLog)->last(), 'timestamp').':'.($isComplete ? $isComplete : 'not complete');
    $resultsKey = $searchQuery.':'.count($progressLog).($isComplete ? $isComplete : 'not complete');
    $footerWidgetData = array_merge($this->getFooterWidgetsData(), ['searchQuery' => $searchQuery])
@endphp

<x-filament-panels::page>
    <div class="space-y-6 -mb-8">
        {{-- Search Form --}}
        <x-filament-panels::form wire:submit="save">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{-- Progress Log --}}
        @if ($showLog)
            <div wire:poll.visible="refreshProgress">
                <livewire:search-log :messages="$progressLog" :complete="$isComplete" wire:key="{{ $searchLogKey }}" />
            </div>
        @endif
    </div>
</x-filament-panels::page>
