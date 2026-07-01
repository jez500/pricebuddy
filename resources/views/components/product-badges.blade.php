@php
    $product = $product ?? $getRecord();
    $latestPrice = $product->getPriceCache()->first();
    $verdict = data_get($product->insights_cache, 'dealScore.verdict');
    $verdictKey = data_get($product->insights_cache, 'dealScore.verdictKey');
    $lowConfidence = (bool) data_get($product->insights_cache, 'dealScore.lowConfidence', false);
    $verdictColor = match ($verdictKey) {
        'great', 'good' => 'success',
        'average' => 'gray',
        'pricey' => 'warning',
        'wait' => 'danger',
        default => 'success',
    };
@endphp
@if (! $product->is_last_scrape_successful || $product->is_notified_price || $latestPrice?->isUnavailable() || $product->paused || $verdict)
    <div {{ $attributes->merge(['class' => 'inline-flex gap-2 mt-1 flex-wrap']) }}>
        @if ($verdict)
            <div class="mt-1 whitespace-nowrap" data-verdict-color="{{ $verdictColor }}">
                @include('components.icon-badge', [
                    'hoverText' => $verdict,
                    'label' => $verdict,
                    'color' => $verdictColor,
                    'icon' => 'heroicon-m-sparkles',
                ])
            </div>
            @if ($lowConfidence)
                <div class="mt-1 whitespace-nowrap">
                    @include('components.icon-badge', [
                        'hoverText' => __('Not enough price history for a confident verdict'),
                        'label' => __('Low confidence'),
                        'color' => 'gray',
                        'icon' => 'heroicon-m-question-mark-circle',
                    ])
                </div>
            @endif
        @endif

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
