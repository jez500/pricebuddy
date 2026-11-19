@php
    /** @var \App\Services\CurrencyConverter $converter */
    $target = $converter->targetCurrency();
    $isRemote = $converter->provider()->isRemote();
    $rows = $converter->status();
@endphp
<div class="text-sm">
    @if ($converter->isEnabled() && ! $isRemote && ($stale = $converter->staleManualRates()))
        <p class="mb-2 text-danger-600 dark:text-danger-400">
            @foreach ($stale as $currency => $enteredFor)
                {{ __('The :currency rate was entered for :old. Enter it again in :target.', ['currency' => $currency, 'old' => $enteredFor, 'target' => $target]) }}<br>
            @endforeach
        </p>
    @endif
    @if (! $converter->isEnabled())
        <p class="text-gray-500 dark:text-gray-400">{{ __('Save your settings to see the rates in use.') }}</p>
    @elseif (empty($rows))
        <p class="text-gray-500 dark:text-gray-400">
            {{ __('All of your stores use :currency, so nothing needs converting.', ['currency' => $target]) }}
        </p>
    @else
        <table class="w-full max-w-xl text-left">
            <thead class="text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-1 pr-4 font-medium">{{ __('Currency') }}</th>
                    <th class="py-1 pr-4 font-medium">{{ __('Rate') }}</th>
                    @if ($isRemote)
                        <th class="py-1 pr-4 font-medium">{{ __('As of') }}</th>
                        <th class="py-1 font-medium">{{ __('Days stored') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 pr-4 font-medium">{{ $row['currency'] }}</td>
                        <td class="py-1 pr-4">
                            @if ($row['rate'] === null)
                                <span class="text-danger-600 dark:text-danger-400">
                                    {{ $isRemote ? __('No rates yet, prices are not converted') : __('No rate set, prices are not converted') }}
                                </span>
                            @else
                                1 {{ $row['currency'] }} = {{ rtrim(rtrim(number_format($row['rate'], 6, '.', ''), '0'), '.') }} {{ $target }}
                            @endif
                        </td>
                        @if ($isRemote)
                            <td class="py-1 pr-4">{{ $row['date'] ?? '-' }}</td>
                            <td class="py-1">{{ $row['days'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($isRemote)
            <p class="mt-2 text-gray-500 dark:text-gray-400">
                {{ __('Rates are fetched every day. Prices from before the first stored rate use the oldest rate available.') }}
            </p>
        @endif
    @endif
</div>
