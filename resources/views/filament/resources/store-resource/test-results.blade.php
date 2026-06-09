@php
    $ai = $ai ?? null;
    $hasAi = filled($ai);

    $fields = [
        'title' => 'Title',
        'price' => 'Price',
        'currency' => 'Currency',
        'image' => 'Image',
        'availability' => 'Availability',
        'description' => 'Description',
    ];

    $isUrl = fn ($v): bool => is_string($v) && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'));
@endphp

<div>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        @php
            $availabilityVal = data_get($scrape, 'availability');
            $availabilityStrategy = data_get($record, 'scrape_strategy.availability');
            $resolvedStatus = \App\Enums\StockStatus::resolveAvailability($availabilityVal, $availabilityStrategy);
            $isSchemaOrgAvailability = data_get($availabilityStrategy, 'type') === \App\Enums\ScraperStrategyType::SchemaOrg->value;
            $matchConfig = data_get($record, 'scrape_strategy.availability.match');

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
                    } elseif (is_string($matchEntry) && trim((string) $availabilityVal) === trim($matchEntry)) {
                        $matchedRule = "exact \"$matchEntry\"";
                        break;
                    }
                }
            }
        @endphp
        {{-- Loader is scoped to the "Change scraper" re-test only. The scrape buttons and the
             Compare with AI action each show their own button loading indicator. --}}
        <div wire:loading wire:target="mountedActionsData.0.test_scraper" class="flex items-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::loading-indicator class="h-5 w-5" />
            {{ __('Scraping…') }}
        </div>

        <div wire:loading.remove wire:target="mountedActionsData.0.test_scraper">
        <table class="w-full text-sm border-collapse table-fixed">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                    <th class="py-2 pr-4 font-semibold w-1/3">Field</th>
                    <th class="py-2 pr-4 font-semibold w-1/3">Scraped</th>
                    @if ($hasAi)
                        <th class="py-2 pr-4 font-semibold w-1/3">AI ✨</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($fields as $key => $label)
                    <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">{{ $label }}</td>

                        <td class="py-2 pr-4">
                            {{-- Scraper has no currency field; AI may. --}}
                            @php $scrapedVal = $key === 'currency' ? null : data_get($scrape, $key); @endphp
                            @if ($key === 'image' && $isUrl($scrapedVal))
                                <img src="{{ $scrapedVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                            @elseif ($key === 'availability' && filled($scrapedVal))
                                {{ $resolvedStatus->getLabel() }}@if ($matchedRule) <span class="text-gray-400">— matched {{ $matchedRule }}</span>@elseif ($isSchemaOrgAvailability) <span class="text-gray-400">— inferred from schema.org</span>@elseif ($resolvedStatus === \App\Enums\StockStatus::InStock) <span class="text-gray-400">— no match (default)</span>@endif
                            @elseif (filled($scrapedVal))
                                <span class="break-words">{{ $scrapedVal }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        @if ($hasAi)
                            <td class="py-2 pr-4">
                                @php $aiVal = data_get($ai, $key); @endphp
                                @if ($key === 'image' && $isUrl($aiVal))
                                    <img src="{{ $aiVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                                @elseif (filled($aiVal))
                                    <span class="break-words">{{ $aiVal }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach

                @if ($hasAi)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Confidence</td>
                        <td class="py-2 pr-4"><span class="text-gray-400">—</span></td>
                        <td class="py-2 pr-4">@if (filled(data_get($ai, 'confidence'))){{ number_format((float) data_get($ai, 'confidence'), 2) }}@else<span class="text-gray-400">—</span>@endif</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @if (filled(data_get($scrape, 'errors')))
            <div class="mt-6">
                <x-filament::section heading="Errors">
                    <code class="block whitespace-pre-wrap break-all text-xs">{{ json_encode(data_get($scrape, 'errors'), JSON_PRETTY_PRINT) }}</code>
                </x-filament::section>
            </div>
        @endif

        @if (filled(data_get($scrape, 'body')))
            <div class="mt-6">
                <x-filament::section heading="Raw HTML body" collapsible collapsed>
                    <code class="block whitespace-pre-wrap break-all max-h-96 overflow-auto text-xs">{{ data_get($scrape, 'body') }}</code>
                </x-filament::section>
            </div>
        @endif
        </div>
    @endif
</div>
