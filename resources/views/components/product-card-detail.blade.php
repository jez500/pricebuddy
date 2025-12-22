@props([
    'product',
    'standalone',
])
@php
    use App\Enums\Trend;
    /** @var \App\Models\Product $product */
    $standalone = ! empty($standalone);
    $extraClasses = $standalone
        ? 'rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 w-full max-w-60 lg:max-w-md'
        : 'rounded-b-xl';
@endphp
<div
    {{ $attributes->merge(['class' => 'product-card-detail w-full '.$extraClasses]) }}
>
    @if ($slot->hasActualContent())
        <button
            style="{{ $product->has_history ? 'height: 42px' : 'padding: .75rem 1rem 1rem; text-align: left' }}"
            @click="expanded = !expanded"
            {{ $attributes->merge(['class' => 'block cursor-pointer display-block w-full transition-colors duration-300 ease-in-out']) }}
        >
            {{ $slot }}
        </button>
    @endif
    <div x-show="expanded">
        <div class="py-2 px-4">
            @include('components.prices-column', ['items' => $product->price_cache])
        </div>

        @include('components.price-aggregates', ['aggregates' => $product->price_aggregates, 'trend' => $product->trend])
    </div>
</div>
