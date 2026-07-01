@php
    use App\Filament\Widgets\NoProductsFound;
@endphp
<x-filament-widgets::widget>
    @foreach ($sections as $section)
        @continue (! $section['visible'])
        @if ($section['key'] === 'stat_bar')
            @include('filament.widgets.dashboard.stat-bar', ['stats' => $sectionData['stat_bar']])
        @elseif ($section['key'] === 'buy_now')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'buy_now', 'heading' => "What's good to buy now", 'icon' => 'heroicon-s-bolt', 'products' => $sectionData['buy_now']])
        @elseif ($section['key'] === 'recently_dropped')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'recently_dropped', 'heading' => 'Recently dropped', 'icon' => 'heroicon-s-arrow-trending-down', 'products' => $sectionData['recently_dropped']])
        @elseif ($section['key'] === 'needs_attention')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'needs_attention', 'heading' => 'Needs attention', 'icon' => 'heroicon-s-exclamation-triangle', 'products' => $sectionData['needs_attention']])
        @endif
    @endforeach

    @if (empty($groups))
        @livewire(NoProductsFound::class)
    @else
        @foreach ($groups as $group)
            <div class="mb-8">
                <h3 class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-s-tag" class="h-5 w-5 pt-1 text-gray-400 dark:text-gray-600" />
                    {{ $group['heading'] }}
                </h3>
                <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($group['products'] as $product)
                        <div>
                            <x-product-card :product="$product" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</x-filament-widgets::widget>
