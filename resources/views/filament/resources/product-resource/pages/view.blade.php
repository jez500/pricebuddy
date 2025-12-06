@php
    /** @var App\Models\Product $record */
    use App\Services\SearchService;
    $searchEnabled = SearchService::canSearch();
    $activeTab = ($searchEnabled && request()->get('searchQuery')) ? 'search' : 'overview';
@endphp
<x-filament-panels::page class="fi-dashboard-page product-view" xmlns:x-filament="http://www.w3.org/1999/html">

    <div x-data="{ tab: '{{ $activeTab }}' }">
        {{-- Tabs--}}
        <x-filament::tabs label="Content tabs" class="justify-stretch sm:justify-start">
            <x-filament::tabs.item @click="tab = 'overview'" :alpine-active="'tab === \'overview\''"
                                   class="w-full sm:w-auto">
                <div class="flex align-center gap-2">
                    <x-filament::icon icon="heroicon-m-rectangle-stack" class="w-4"/>
                    {{ __('Overview') }}
                </div>
            </x-filament::tabs.item>

            <x-filament::tabs.item @click="tab = 'history'" :alpine-active="'tab === \'history\''"
                                   class="w-full sm:w-auto">
                <div class="flex align-center gap-2">
                    <x-filament::icon icon="heroicon-m-chart-bar" class="w-4"/>
                    {{ __('History') }}
                </div>
            </x-filament::tabs.item>

            @if ($searchEnabled)
                <x-filament::tabs.item @click="tab = 'search'" :alpine-active="'tab === \'search\''"
                                       class="w-full sm:w-auto">
                    <div class="flex align-center gap-2">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="w-4"/>
                        {{ __('Search') }}
                    </div>
                </x-filament::tabs.item>
            @endif
        </x-filament::tabs>

        {{-- Tab content --}}
        <div class="mt-8">
            <div x-show="tab === 'overview'">
                <div class="flex gap-3 md:gap-8 flex-col md:flex-row">
                    <div class="md:w-1/3 flex flex-col">
                        <div class="bg-white rounded-lg p-4 mb-4 h-auto w-full flex justify-center shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
                            <div class="">
                                <img
                                    src="{{ $record->primary_image }}"
                                    alt="{{ $record->title }}"
                                    class="rounded-lg h-auto w-full block max-h-72 md:max-h-96"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 flex flex-col md:h-full mb-2">
                        <div>
                            @livewire(\App\Filament\Resources\ProductResource\Widgets\ProductUrlStats::class, ['record'
                            => $record])
                        </div>

                        <div class="mt-6 md:mt-8">
                            <div class="pb-2 gap-4 md:flex-row flex flex-col md:items-start">
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('Created :date', ['date' => $record->created_at->diffForHumans()]) }}
                                    {{ $record->tags->count() > 0 ? __('in').':' : '' }}
                                </div>
                                <div class="flex gap-2 flex-wrap items-center">
                                    @foreach($record->tags as $tag)
                                        <x-filament::badge
                                            tag="a"
                                            color="gray"
                                            class="mb-1"
                                            icon="heroicon-s-tag"
                                            href="/admin/products?tableFilters[tags][values][0]={{ $tag->id }}"
                                        >{{ $tag->name }}</x-filament::badge>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="tab === 'history'">
                @livewire(\App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart::class, ['record' =>
                $record, 'lazy' => true])
                <x-filament::section :heading="__('Min/max price history')" class="mt-6">
                    <x-range-chart :product="$record"/>
                </x-filament::section>
            </div>

            @if ($searchEnabled)
                <div x-show="tab === 'search'">
                    @livewire(\App\Filament\Resources\ProductResource\Widgets\CreateViaSearchForm::class, ['product' => $record, 'lazy' => true])
                </div>
            @endif

        </div>
    </div>

</x-filament-panels::page>
