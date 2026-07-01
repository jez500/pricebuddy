@php
    use App\Enums\Trend;
    use function Filament\Support\get_color_css_variables;

    /** @var \App\Models\Product $product */
    /** @var \App\Dto\PriceCacheDto $latestPrice */
    $latestPrice = $product->getPriceCache()->first();

@endphp
<div
    style="{{ Filament\Support\get_color_css_variables(Trend::getColor($product->trend), shades: [300, 500, 400, 600, 800]) }}"
    {{ $attributes->merge(['class' => 'pb-expandable-stat display-block w-full rounded-xl bg-gray-100 dark:bg-gray-800/30 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10']) }}
    x-data="{ expanded: false }"
>
    <div class="flex">
        <div
            class="flex-1 bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 rounded-r-xl"
            :class="expanded ? 'rounded-bl-none' : ''"
        >
            <a class="flex gap-2" href="{{ $product->view_url }}">
                <div @class(['w-20 h-20 min-w-20 m-2 rounded-md overflow-hidden p-1 flex items-center', 'cursor-grab' => $draggable]) @if ($draggable) x-sortable-handle @endif>
                    <x-product-image :product="$product" />
                </div>
                <div class="my-1 flex flex-col min-w-0 justify-center" style="width: calc(100% - 5rem)">
                    <h3
                        class="mb-1 mt-2 text-sm text-gray-500 dark:text-gray-400 font-bold truncate min-w-0"
                        style="max-width: 13rem"
                        title="{{ $product->title }}"
                    >
                        {{ $product->title }}
                    </h3>
                    <div>
                        @if ($latestPrice)
                            @if ($latestPrice->hasVisiblePrice())
                                <span class="text-3xl font-semibold leading-none">
                                    {{ $latestPrice->getUnitPriceFormatted() }}
                                </span>

                            @else
                                <span class="text-lg font-semibold text-gray-500 dark:text-gray-400">
                                    {{ __('Unavailable') }}
                                </span>
                            @endif
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-bold display-block">
                                @if ($latestPrice->hasPriceFactor())
                                    {{ __('per :unit', ['unit' => $latestPrice->getUnitOfMeasure()]) }}
                                @endif
                                {{ '@'.$latestPrice->getStoreName() }}
                            </span>
                        @else
                            <span class="text-lg font-semibold text-gray-500 dark:text-gray-400">
                                {{ __('Unavailable') }}
                            </span>
                        @endif
                    </div>
                    @if ($latestPrice?->hasPriceFactor())
                        <span class="text-xs text-gray-500 dark:text-gray-400 block mb-2 mt-1">
                            <x-price-factor-price :cache="$latestPrice" />
                        </span>
                    @endif
                    <div class="pb-card-badges block mb-2">
                        @include('components.product-badges', ['product' => $product])
                    </div>
                </div>
            </a>

            <div class="bg-custom-400/10 hover:bg-custom-400/20 rounded-br overflow-hidden">
                <x-range-chart :product="$product" height="40px"/>
            </div>

        </div>
        <div class="pb-expandable-stat__context">
            <button
                class="pb-expandable-stat__context-button h-full opacity-50 hover:opacity-100 py-3 bg-gray-100 dark:bg-gray-950 rounded-xl border dark:border-gray-500 border-neutral-500/50 dark:hover:border-neutral-700 border-t-0 border-r-0 rounded-tl-none rounded-br-none"
                :class="expanded ? 'items-end' : 'collapsed items-start'"
                @click="expanded = !expanded"
            >
                <x-filament::icon icon="heroicon-s-chevron-up" class="h-5 w-5"/>
            </button>
        </div>
    </div>

    @livewire('product-card-detail', ['product' => $product], key('product-card-detail-'.$product->id))
</div>
