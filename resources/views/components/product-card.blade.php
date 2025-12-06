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
                <div class="w-20 h-20 min-w-20 m-2 rounded-md overflow-hidden p-1 flex items-center">
                    <img src="{{ $product->primary_image }}" alt="{{ $product->title }}"
                         class="rounded-md display-block h-auto block w-20"/>
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
                        <span class="text-2xl font-semibold">
                            {{ $latestPrice->getPriceFormatted() }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-bold display-block">
                            {{ '@'.$latestPrice->getStoreName() }}
                        </span>
                        <div class="block mb-2">
                            @include('components.product-badges', ['product' => $product])
                        </div>
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
