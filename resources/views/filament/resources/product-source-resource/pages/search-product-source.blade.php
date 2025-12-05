@php
    $searchLogKey = $searchQuery.':'.count($progressLog).':'.data_get(collect($progressLog)->last(), 'timestamp').':'.($isComplete ? $isComplete : 'not complete');
    $resultsKey = $searchQuery.':'.count($progressLog).($isComplete ? $isComplete : 'not complete');
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Search Form --}}
        <x-filament-panels::form wire:submit="save">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{-- Progress Log --}}
        @if ($searchQuery && !empty($progressLog))
            <div wire:poll.visible="refreshProgress">
                <livewire:search-log :messages="$progressLog" :complete="$isComplete" wire:key="{{ $searchLogKey }}" />
            </div>
        @endif

        {{-- Results Table --}}
        @if ($isComplete && $searchQuery)
            <div wire:key="{{ $resultsKey }}" wire:loading.delay.longer.class="opacity-10">
                <x-filament-widgets::widgets
                    :columns="$this->getFooterWidgetsColumns()"
                    :data="$this->getFooterWidgetsData()"
                    :widgets="$this->getFooterWidgets()"
                />
            </div>
        @endif
    </div>
</x-filament-panels::page>
