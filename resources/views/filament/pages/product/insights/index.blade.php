@php
    use App\Enums\Trend;
    use Illuminate\Support\Number;

    /** @var App\Models\Product $record */
    $insights = \App\Services\Insights\ProductInsights::for($record);
    $currency = $record->urls->first()?->store?->currency ?? 'USD';
    $money = fn ($value) => Number::currency((float) $value, in: $currency);
    $verdictColors = [
        'great' => 'text-primary-600 dark:text-primary-400',
        'good' => 'text-primary-600 dark:text-primary-400',
        'average' => 'text-gray-600 dark:text-gray-300',
        'pricey' => 'text-amber-600 dark:text-amber-400',
        'wait' => 'text-danger-600 dark:text-danger-400',
    ];
    $cardClass = 'bg-white dark:bg-gray-900 rounded-xl p-5 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10';
@endphp

<div class="space-y-4">
    <style>
        @media (max-width: 767px) {
            .pb-chart-scroll .fi-section-content { overflow-x: auto; }
            .pb-chart-scroll [wire\:ignore] { min-width: 700px; }
        }
    </style>
    @if (! $insights->hasEnoughData)
        <div class="{{ $cardClass }} text-center py-10">
            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200">{{ __('Not enough price history yet') }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('Insights appear once a few prices have been recorded.') }}</p>
        </div>
    @else
        {{-- HERO --}}
        @php $score = $insights->dealScore; @endphp
        <div class="{{ $cardClass }} grid grid-cols-1 md:grid-cols-[1.6fr_1fr] gap-6 items-center"
             style="background-image: linear-gradient(120deg, rgba(45,212,191,.08), transparent); border-color: rgba(45,212,191,.5)">
            <div>
                <div class="text-base font-semibold text-primary-600 dark:text-primary-400">{{ __('Should I buy right now?') }}</div>
                <div class="text-2xl md:text-3xl font-extrabold leading-tight {{ $verdictColors[$score->verdictKey] }}">{{ $score->verdict }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    @if ($score->isAllTimeLow)
                        {{ __('This is the cheapest it has ever been.') }}
                    @else
                        {{ __(':pct% cheaper than the rest of the year', ['pct' => $insights->percentile->percentCheaperThan]) }}
                    @endif
                    @if ($score->lowConfidence)
                        · <span class="text-amber-600 dark:text-amber-400">{{ __('limited history') }}</span>
                    @endif
                </div>
            </div>
            <div class="md:text-right">
                <div class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ $money($insights->bestPrice) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('best at :store', ['store' => $insights->bestStore ?? '—']) }}</div>
                <span class="inline-block mt-2 bg-primary-500 text-primary-950 text-xs font-bold px-2.5 py-1 rounded-full">
                    {{ __('cheaper than :pct% of the year', ['pct' => $insights->percentile->percentCheaperThan]) }}
                </span>
            </div>
        </div>

        {{-- STAT STRIP --}}
        @php $stats = $insights->stats; @endphp
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="{{ $cardClass }}">
                <div class="text-base text-gray-500 dark:text-gray-400">{{ __('All-time low') }}</div>
                <div class="text-xl font-extrabold text-gray-900 dark:text-white">{{ $money($stats->lowest) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $stats->lowestStore }} · {{ $stats->lowestDate ? \Illuminate\Support\Carbon::parse($stats->lowestDate)->format('j M Y') : '' }}</div>
            </div>
            <div class="{{ $cardClass }}">
                <div class="text-base text-gray-500 dark:text-gray-400">{{ __('All-time high') }}</div>
                <div class="text-xl font-extrabold text-gray-900 dark:text-white">{{ $money($stats->highest) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $stats->highestDate ? \Illuminate\Support\Carbon::parse($stats->highestDate)->format('j M Y') : '' }}</div>
            </div>
            <div class="{{ $cardClass }}">
                <div class="text-base text-gray-500 dark:text-gray-400">{{ __('12-mo average') }}</div>
                <div class="text-xl font-extrabold text-gray-900 dark:text-white">{{ $money($stats->average) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('across all stores') }}</div>
            </div>
            <div class="{{ $cardClass }}">
                <div class="text-base text-gray-500 dark:text-gray-400">{{ __('Today vs avg') }}</div>
                <div class="text-xl font-extrabold {{ $stats->percentVsAverage <= 0 ? 'text-primary-600 dark:text-primary-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ $stats->percentVsAverage > 0 ? '+' : '' }}{{ (int) $stats->percentVsAverage }}%
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $money(abs($stats->current - $stats->average)) }} {{ $stats->percentVsAverage <= 0 ? __('below') : __('above') }} avg</div>
            </div>
        </div>

        {{-- MAIN CHART --}}
        <div
            class="pb-chart-scroll"
            x-data
            x-init="
                let tries = 0;
                const interval = setInterval(() => {
                    const content = $el.querySelector('.fi-section-content');
                    if (content && content.scrollWidth > content.clientWidth + 4) {
                        content.scrollLeft = content.scrollWidth;
                        clearInterval(interval);
                    }
                    if (++tries > 20) clearInterval(interval);
                }, 300);
            "
        >
            @livewire(\App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart::class, ['record' => $record, 'lazy' => true])
        </div>

        {{-- INSIGHT GRID --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">

            {{-- Distribution --}}
            @php $dist = $insights->distribution; $maxCount = max(1, collect($dist->bins)->max('count')); @endphp
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Price distribution') }}</div>
                <div class="flex items-end gap-1.5 h-24">
                    @foreach ($dist->bins as $bin)
                        <div class="flex-1 rounded-t {{ $bin['isCurrent'] ? 'bg-primary-500' : 'bg-primary-500/40' }}"
                             style="height: {{ max(4, (int) round($bin['count'] / $maxCount * 100)) }}%"
                             title="{{ $money($bin['min']) }}–{{ $money($bin['max']) }}: {{ $bin['count'] }}"></div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">{{ __("Today's price is in a band reached on :pct% of days.", ['pct' => $dist->currentBinPercent]) }}</p>
            </div>

            {{-- Store showdown --}}
            @php $maxPrice = max(0.01, $insights->storeShowdown->max('currentPrice')); @endphp
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Store showdown') }}</div>
                @foreach ($insights->storeShowdown as $store)
                    <div class="flex items-center gap-2 my-2 text-xs">
                        <span class="w-20 truncate {{ $store->isCheapestToday ? 'font-bold text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300' }}">{{ $store->storeName }}</span>
                        <span class="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                            <span class="block h-full bg-primary-500" style="width: {{ (int) round($store->currentPrice / $maxPrice * 100) }}%"></span>
                        </span>
                        <span class="w-12 text-right font-semibold text-gray-900 dark:text-white">{{ $money($store->currentPrice) }}</span>
                        <span class="w-14 text-right text-gray-400">{{ __('won :pct%', ['pct' => (int) round($store->winRate * 100)]) }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Recent drops --}}
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Recent price drops') }}</div>
                @forelse ($insights->dropEvents as $event)
                    <div class="flex items-center gap-2 py-2 text-xs border-b border-dashed border-gray-200 dark:border-white/10 last:border-0">
                        <span class="w-2 h-2 rounded-full {{ $event->isDrop ? 'bg-primary-500' : 'bg-danger-500' }}"></span>
                        <span class="flex-1 text-gray-700 dark:text-gray-300">{{ $event->storeName }}</span>
                        <span class="font-bold {{ $event->isDrop ? 'text-primary-600 dark:text-primary-400' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ $money($event->change) }} ({{ $event->changePercent > 0 ? '+' : '' }}{{ $event->changePercent }}%)
                        </span>
                        <span class="w-14 text-right text-gray-400">{{ \Illuminate\Support\Carbon::parse($event->date)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
                    </div>
                @empty
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('No notable price moves recently.') }}</p>
                @endforelse
            </div>

            {{-- Seasonality --}}
            @php $season = $insights->seasonality; $monthsLbl = ['','J','F','M','A','M','J','J','A','S','O','N','D']; $maxMonth = max(0.01, collect($season->monthlyAverages)->filter()->max() ?? 0.01); @endphp
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Best month to buy') }}</div>
                @if ($season->hasEnoughData)
                    <div class="flex items-end gap-1 h-24">
                        @for ($m = 1; $m <= 12; $m++)
                            <div class="flex-1 rounded-t {{ in_array($m, $season->cheapestMonths) ? 'bg-primary-500' : 'bg-primary-500/40' }}"
                                 style="height: {{ $season->monthlyAverages[$m] ? max(6, (int) round($season->monthlyAverages[$m] / $maxMonth * 100)) : 2 }}%"></div>
                        @endfor
                    </div>
                    <div class="flex gap-1 mt-1 text-[8px] text-gray-400">
                        @for ($m = 1; $m <= 12; $m++)<span class="flex-1 text-center">{{ $monthsLbl[$m] }}</span>@endfor
                    </div>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Needs about a year of history to spot seasonal patterns.') }}</p>
                @endif
            </div>

            {{-- Availability --}}
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Availability over time') }}</div>
                @foreach ($insights->availability as $avail)
                    @php $segTotal = max(1, collect($avail->segments)->sum('days')); @endphp
                    <div class="my-2.5">
                        <div class="flex justify-between text-[10px] text-gray-500 dark:text-gray-400 mb-1">
                            <span>{{ $avail->storeName }}</span><span>{{ (int) $avail->inStockPercent }}% {{ __('in stock') }}</span>
                        </div>
                        <div class="flex h-3 rounded overflow-hidden">
                            @foreach ($avail->segments as $segment)
                                <span class="{{ $segment['available'] ? 'bg-primary-500' : 'bg-danger-500' }}" style="width: {{ round($segment['days'] / $segTotal * 100, 2) }}%"></span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Target tracker --}}
            <div class="{{ $cardClass }}">
                <div class="text-base font-semibold text-gray-950 dark:text-white mb-3">{{ __('Target tracker') }}</div>
                @if ($insights->targetTracker)
                    @php $t = $insights->targetTracker; @endphp
                    <div class="text-xs text-gray-700 dark:text-gray-300 mb-3">{{ __('Now') }} <b>{{ $money($t->current) }}</b> · {{ __('target') }} <b class="text-primary-600 dark:text-primary-400">{{ $money($t->target) }}</b></div>
                    <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div class="h-full bg-primary-500" style="width: {{ $t->progressPercent }}%"></div>
                    </div>
                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-2">
                        {{ $t->progressPercent }}% {{ __('of the way there') }} · {{ __('hit on :pct% of days', ['pct' => $t->hitPercent]) }}{{ $t->lastHitDate ? ' · '.__('last hit :date', ['date' => \Illuminate\Support\Carbon::parse($t->lastHitDate)->format('j M Y')]) : '' }}
                    </div>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Set a target price on this product to track how close deals get.') }}</p>
                @endif
            </div>

        </div>
    @endif
</div>
