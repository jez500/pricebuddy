@php
    use App\Enums\Trend;
    /** @var \App\Models\Product $product */
    $extraClasses = $standalone
        ? 'rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 w-full max-w-60 lg:max-w-md'
        : 'rounded-b-xl';
@endphp
<div
    class="product-card-detail w-full {{ $extraClasses }}"
>
    @if ($showChart)
        <button
            style="{{ $product->has_history ? 'height: 42px' : 'padding: .75rem 1rem 1rem; text-align: left' }}"
            @click="expanded = !expanded"
            class="block cursor-pointer display-block w-full transition-colors duration-300 ease-in-out"
        >
            <div class="bg-custom-400/10 hover:bg-custom-400/20 rounded-lg" style="height: 40px" :class="expanded ? 'rounded-b-none' : ''">
                <x-range-chart :product="$product" height="40px" class="rounded-lg" />
            </div>
        </button>
    @endif
    <div x-show="expanded">

        <div class="pt-1 px-2">
            @include('components.prices-column', ['items' => $product->price_cache])
        </div>

        <div class="pb-expandable-stat__actions px-2 pb-2 py-1 flex gap-2 justify-start items-center text-gray-500 dark:text-gray-400">
            {{ $this->addUrlAction }}
            {{ $this->editAction }}
            {{ $this->fetchAction }}
            {{ $this->deleteAction }}
        </div>

        @include('components.price-aggregates', ['aggregates' => $product->price_aggregates, 'trend' => $product->trend])
    </div>

    <x-filament-actions::modals />
</div>
