@php
    $searchLogKey = $searchQuery.':'.count($progressLog).':'.data_get(collect($progressLog)->last(), 'timestamp').':'.($isComplete ? $isComplete : 'not complete');
    $resultsKey = $searchQuery.':'.count($progressLog).($isComplete ? $isComplete : 'not complete');
@endphp
<x-filament-widgets::widget>

    @if (! $product)
        <h3 class="fi-header-heading my-6 text-2xl font-bold tracking-tight text-gray-950 dark:text-white" id="searchHeading">
            Or search for a product
        </h3>
    @endif

    <x-filament-panels::form
        id="form"
        :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
        wire:submit="save"
    >
        {{ $this->form }}
    </x-filament-panels::form>

    <div class="min-h-96">

        @if ($showLog)
            <div @unless($isComplete) wire:poll.visible.2s="refreshProgress" @endunless x-init="window.document.getElementById('searchHeading')?.scrollIntoView({behavior: 'smooth'})">
                <livewire:search-log :messages="$progressLog" :complete="(bool) $isComplete" wire:key="{{ $searchLogKey }}" />
            </div>
        @endif

        @if ($isComplete && $searchQuery)
            <div wire:key="{{ $resultsKey }}" wire:loading.delay.longer.class="opacity-10" class="mt-6" x-init="window.document.getElementById('searchHeading')?.scrollIntoView({behavior: 'smooth'})">
                @livewire(\App\Filament\Resources\ProductResource\Widgets\CreateViaSearchTable::class, [
                    'searchQuery' => $searchQuery,
                    'filters' => $filters,
                    'product' => $product,
                ])
            </div>
        @endif

    </div>

</x-filament-widgets::widget>
