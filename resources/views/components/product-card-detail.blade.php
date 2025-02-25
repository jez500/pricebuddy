@php
    use App\Enums\Trend;
    /** @var \App\Models\Product $product */
    $standalone = ! empty($standalone);
    $extraClasses = $standalone
        ? 'rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10'
        : 'rounded-bl-xl rounded-br-xl';
@endphp
<div
    class="mt-1 w-full border-t border-t-gray-200 dark:border-t-gray-800 bg-gray-200/20 dark:bg-gray-950/20 {{ $extraClasses }}"
    x-data="{ expanded: false }"
>
    <button
        class="py-2 bg-custom-400/10 hover:bg-custom-400/20 cursor-pointer display-block w-full transition-colors duration-300 ease-in-out"
        style="{{ $product->has_history ? 'height: 60px;' : 'padding: .75rem 1rem 1rem; text-align: left' }}"
        @click="expanded = !expanded"
    >
        @if ($product->has_history)
            <x-range-chart :product="$product" height="50px"/>
        @else
            <span class="text-xs text-gray-500 dark:text-gray-400 flex gap-2">
                    <span>{{ __('No trend yet') }}</span>
                    <x-filament::icon
                        icon="heroicon-s-chevron-down"
                        class="w-4 ml-auto"
                        x-bind:class="{ 'transform rotate-180': expanded }"
                    />
                </span>
        @endif

    </button>
    <div x-show="expanded">
        <div class="py-2 px-4 border-t border-t-gray-200 dark:border-t-gray-800 bg-white dark:bg-gray-900">
            @include('components.prices-column', ['items' => $product->price_cache])
        </div>
        <div class="mt-1 py-2 px-4 gap-2 flex border-t border-t-gray-200 dark:border-t-gray-800">
            @foreach (['min', 'avg', 'max'] as $agg)
                <div class="text-xs text-gray-500 dark:text-gray-400 pr-2">
                    {{ ucfirst($agg) }}: {{ $product->price_aggregates[$agg] }}
                </div>
            @endforeach
            <x-filament::icon
                :icon="Trend::getIcon($product->trend)"
                class="ml-auto w-4 text-custom-600 dark:text-custom-400"
                title="Price trending {{ $product->trend }}"
            />
        </div>
    </div>
</div>
