@php
    $product = $product ?? $getRecord();
    $latestPrice = $product->getPriceCache()->first();
@endphp
@if (! $product->is_last_scrape_successful || $product->is_notified_price || $latestPrice?->isUnavailable() || $product->paused)
    <div {{ $attributes->merge(['class' => 'inline-flex gap-2 mt-1 flex-wrap']) }}>
        @if ($product->paused)
            <div class="mt-1 whitespace-nowrap">
                @include('components.icon-badge', [
                    'hoverText' => __('Price checking is paused for this product'),
                    'label' => __('Paused'),
                    'color' => 'gray',
                    'icon' => 'heroicon-m-pause',
                ])
            </div>
        @endif

        @if ($latestPrice?->isUnavailable())
            <div class="mt-1 whitespace-nowrap">
                @include('components.icon-badge', [
                    'hoverText' => __('This item is currently :status', ['status' => strtolower($latestPrice->getStockStatusLabel())]),
                    'label' => __($latestPrice->getStockStatusLabel()),
                    'color' => $latestPrice->getStockStatusColor(),
                    'icon' => $latestPrice->getStockStatusIcon(),
                ])
            </div>
        @endif

        @if (! $product->is_last_scrape_successful)
            <div class="mt-1 whitespace-nowrap">
                @include('components.icon-badge', [
                    'hoverText' => __('One or more urls failed last scrape'),
                    'label' => __('Scrape error'),
                    'color' => 'warning',
                ])
            </div>
        @endif

        @if ($product->is_notified_price)
            <div class="mt-1 whitespace-nowrap">
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
