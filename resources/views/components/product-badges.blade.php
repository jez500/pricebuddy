@php
    $product = $product ?? $getRecord();
@endphp
@if (! $product->is_last_scrape_successful || $product->is_notified_price)
    <div {{ $attributes }} class="inline-flex gap-2 mt-1">
        @if (! $product->is_last_scrape_successful)
            <div class="mt-1">
                @include('components.icon-badge', [
                    'hoverText' => __('One or more urls failed last scrape'),
                    'label' => __('Scrape error'),
                    'color' => 'warning',
                ])
            </div>
        @endif

        @if ($product->is_notified_price)
            <div class="mt-1">
                @include('components.icon-badge', [
                'hoverText' => __('Price matches your target'),
                'label' => __('Notify match'),
                'color' => 'success',
                'icon' => 'heroicon-m-shopping-bag'
            ])
            </div>
        @endif
    </div>
@endif
