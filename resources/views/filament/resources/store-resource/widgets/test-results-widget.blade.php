<x-filament-widgets::widget>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        @foreach($scrape as $key => $val)
            @if ($key !== 'store')
                <div class="mb-8">
                    <x-filament::section :heading="str_replace('_', ' ', ucfirst($key))">
                        <code class="block whitespace-pre overflow-x-auto">{{ is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT) }}</code>
                    </x-filament::section>
                    @if ($key === 'availability')
                        @php
                            $matchConfig = data_get($record, 'scrape_strategy.availability.match');
                            $resolvedStatus = \App\Enums\StockStatus::matchFromScrapedValue($val, $matchConfig);

                            $matchedRule = null;
                            if (is_array($matchConfig)) {
                                foreach ($matchConfig as $statusValue => $matchEntry) {
                                    if ($statusValue === 'default' || $matchEntry === '' || $matchEntry === null) {
                                        continue;
                                    }
                                    if (is_array($matchEntry)) {
                                        $matchValue = $matchEntry['value'] ?? '';
                                        $matchType = $matchEntry['type'] ?? 'match';
                                        if ($matchValue !== '' && \App\Enums\StockStatus::tryFrom($statusValue)?->value === $resolvedStatus->value) {
                                            $matchedRule = $matchType === 'regex' ? "regex \"$matchValue\"" : "exact \"$matchValue\"";
                                            break;
                                        }
                                    } elseif (is_string($matchEntry) && trim($val) === trim($matchEntry)) {
                                        $matchedRule = "exact \"$matchEntry\"";
                                        break;
                                    }
                                }
                            }
                        @endphp
                        <div class="mt-8">
                            <x-filament::section heading="Product status">
                                <code class="block whitespace-pre overflow-x-auto">{{ $resolvedStatus->getLabel() }}@if ($matchedRule) — matched {{ $matchedRule }}@elseif ($resolvedStatus === \App\Enums\StockStatus::InStock) — no match (default)@endif</code>
                            </x-filament::section>
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    @endif
</x-filament-widgets::widget>
