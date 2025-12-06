@php
    use Filament\Support\Enums\IconPosition;
    use Filament\Support\Facades\FilamentView;
    use function Filament\Support\get_color_css_variables;

    /** @var \App\Models\Product $product */
    /** @var \App\Dto\PriceCacheDto $priceCache */
    $chartColor = $getChartColor() ?? 'gray';
    $descriptionColor = $getDescriptionColor() ?? 'gray';
    $descriptionIcon = $getDescriptionIcon();
    $descriptionIconPosition = $getDescriptionIconPosition();
    $url = $getUrl();
    $tag = $url ? 'a' : 'div';
    $dataChecksum = $generateDataChecksum();

    $descriptionIconClasses = \Illuminate\Support\Arr::toCssClasses([
        'fi-wi-stats-overview-stat-description-trend-icon h-4 w-4 opacity-90 inline-block ml-2',
        match ($descriptionColor) {
            'gray' => 'text-gray-400 dark:text-gray-500',
            default => 'text-custom-500',
        },
    ]);

    $descriptionIconStyles = \Illuminate\Support\Arr::toCssStyles([
        get_color_css_variables(
            $descriptionColor,
            shades: [500],
            alias: 'widgets::stats-overview-widget.stat.description.icon',
        ) => $descriptionColor !== 'gray',
    ]);

    $firstCardValueStyle = $idx === 0
        ? 'text-2xl md:text-4xl font-bold tracking-tight text-gray-950 dark:text-white'
        : 'text-xl md:text-3xl font-semibold tracking-tight text-gray-500 dark:text-gray-400';

    $wrapperStyle = $idx === 0
        ? 'p-6 px-5'
        : 'p-4 pb-6 px-5';
@endphp
<div
    class="fi-wi-stats-overview-stat pb-expandable-stat bg-gray-100 dark:bg-gray-800/30 ring-1 ring-gray-950/5 dark:ring-white/10 rounded-xl"
    x-data="{ expanded: false }"
    :class="expanded ? 'expanded' : 'collapsed'"
>
    <div class="pb-expandable-stat__top">
    <{!! $tag !!}
    @if ($url)
        {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab()) }}
    @endif
    {{
        $getExtraAttributeBag()
            ->class([
                'pb-expandable-stat__card flex-1 relative block rounded-xl rounded-tr-none bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 '.$wrapperStyle,
            ])
    }}
    >
    <div class="flex mb-4 gap-4">

        <div
            class="fi-wi-stats-overview-stat-value {{ $firstCardValueStyle }}"
        >
            {{ $getValue() }}
        </div>

        <div class="flex items-center gap-x-2 justify-start">
            @if ($icon = $getIcon())
                <x-filament::icon
                    :icon="$icon"
                    class="fi-wi-stats-overview-stat-icon h-5 w-5 text-gray-400 dark:text-gray-500"
                />
            @endif

            <span
                class="fi-wi-stats-overview-stat-label text-sm font-medium text-gray-500 dark:text-gray-400"
            >
                {{ $getLabel() }}

                @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::After, 'after']))
                    <x-filament::icon
                        :icon="$descriptionIcon"
                        :class="$descriptionIconClasses"
                        :style="$descriptionIconStyles"
                    />
                @endif
            </span>
        </div>

        @if ($description = $getDescription())
            <div class="flex items-center ml-auto gap-2 mb-0" title="{{ $description }}">
                @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::Before, 'before']))
                    <x-filament::icon
                        :icon="$descriptionIcon"
                        :class="$descriptionIconClasses.' w-8 h-8'"
                        :style="$descriptionIconStyles"
                    />
                @endif
            </div>
        @endif
    </div>

    @if (! $priceCache->isLastScrapeSuccessful() || $priceCache->matchesNotification($product))
        <div class="mt-2 pb-4 inline-flex gap-2">
            @if (! $priceCache->isLastScrapeSuccessful())
                <div class="mb-2">
                    @include('components.icon-badge', [
                        'hoverText' => __('Last scrape successful was :hours hours ago', [
                            'hours' => round($priceCache->getHoursSinceLastScrape(), 2) ?? 'never'
                        ]),
                        'label' => __('Last scrape '.ceil($priceCache->getHoursSinceLastScrape()).'hrs ago'),
                         'color' => 'warning',
                    ])
                </div>
            @endif

            @if ($priceCache->matchesNotification($product))
                <div>
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

    @if ($chart = $getChart())
        {{-- An empty function to initialize the Alpine component with until it's loaded with `ax-load`. This removes the need for `x-ignore`, allowing the chart to be updated via Livewire polling. --}}
        <div x-data="{ statsOverviewStatChart: function () {} }">
            <div
                @if (FilamentView::hasSpaMode())
                    ax-load="visible"
                @else
                    ax-load
                @endif
                ax-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('stats-overview/stat/chart', 'filament/widgets') }}"
                x-data="statsOverviewStatChart({
                                dataChecksum: @js($dataChecksum),
                                labels: @js(array_keys($chart)),
                                values: @js(array_values($chart)),
                            })"
                @class([
                    'pb-expandable-stat__chart absolute inset-x-0 bottom-0 overflow-hidden rounded-b-xl',
                    match ($chartColor) {
                        'gray' => null,
                        default => 'fi-color-custom',
                    },
                    is_string($chartColor) ? "fi-color-{$chartColor}" : null,
                ])
                @style([
                    get_color_css_variables(
                        $chartColor,
                        shades: [50, 400, 500],
                        alias: 'widgets::stats-overview-widget.stat.chart',
                    ) => $chartColor !== 'gray',
                ])
            >
                <canvas x-ref="canvas" class="h-6"></canvas>

                <span
                    x-ref="backgroundColorElement"
                        @class([
                            match ($chartColor) {
                                'gray' => 'text-gray-100 dark:text-gray-800',
                                default => 'text-custom-50 dark:text-custom-400/10',
                            },
                        ])
                    ></span>

                <span
                    x-ref="borderColorElement"
                        @class([
                            match ($chartColor) {
                                'gray' => 'text-gray-400',
                                default => 'text-custom-500 dark:text-custom-400',
                            },
                        ])
                    ></span>
            </div>
        </div>
    @endif

    </{!! $tag !!}>
    <div class="pb-expandable-stat__context">
        <button
            class="pb-expandable-stat__context-button h-full opacity-50 hover:opacity-100 py-3 bg-gray-100 dark:bg-gray-950 rounded-xl border dark:border-gray-500 border-neutral-500/50 dark:hover:border-neutral-700 border-t-0 border-r-0 rounded-tl-none rounded-br-none collapsed items-start"
            :class="expanded ? 'rotate-180' : 'collapsed'"
            @click="expanded = !expanded"
        >
            <x-filament::icon icon="heroicon-s-chevron-down" class="h-5 w-5" />
        </button>
    </div>
    </div>

    <div x-show="expanded">
        <div class="pb-expandable-stat__actions px-3 pt-4 pb-3 flex gap-2 justify-start items-center text-gray-500 dark:text-gray-400">
            {{ ($this->viewAction)(['url' => $priceCache->getUrl()]) }}
            {{ ($this->fetchAction)(['url' => $priceCache->getUrlId()]) }}
            {{ ($this->deleteAction)(['url' => $priceCache->getUrlId()]) }}
         </div>
        @include('components.price-aggregates', [
            'aggregates' => $priceCache->getAggregateFormatted(),
            'trend' => $priceCache->getTrend(),
            'hideTrend' => true,
            'age' => $priceCache->getFirstDate(),
        ])
    </div>

    <x-filament-actions::modals />

</div>
