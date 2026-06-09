@php
    $fields = $preview['fields'] ?? [];
    $extracted = $preview['extracted'] ?? [];
    $usedBrowser = $preview['usedBrowser'] ?? false;
    $labels = ['title' => 'Title', 'price' => 'Price', 'image' => 'Image', 'availability' => 'Availability'];
@endphp

<div>
    @if ($usedBrowser)
        <p class="mb-3 text-sm text-amber-600 dark:text-amber-400">
            {{ __('Browser scraping required — applying will set the scraper service to Api.') }}
        </p>
    @endif

    <table class="w-full text-sm border-collapse table-fixed">
        <thead>
            <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                <th class="py-2 pr-4 font-semibold w-1/4">Field</th>
                <th class="py-2 pr-4 font-semibold w-1/2">Proposed selector</th>
                <th class="py-2 pr-4 font-semibold w-1/4">Extracted</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($labels as $key => $label)
                <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                    <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">{{ $label }}</td>
                    <td class="py-2 pr-4">
                        @php $slot = $fields[$key] ?? null; @endphp
                        @if ($slot)
                            <span class="text-gray-400">{{ $slot['type'] }}</span>
                            <code class="break-all">{{ $slot['value'] }}</code>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">
                        @php $val = $extracted[$key] ?? null; @endphp
                        @if (filled($val))<span class="break-words">{{ $val }}</span>@else<span class="text-gray-400">—</span>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
