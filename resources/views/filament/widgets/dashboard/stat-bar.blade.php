<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    @php($cells = [
        ['label' => 'Tracked', 'value' => $stats['tracked']],
        ['label' => 'All-time low', 'value' => $stats['atLowest']],
        ['label' => 'Below average', 'value' => $stats['belowAverage']],
        ['label' => 'Out of stock', 'value' => $stats['outOfStock']],
        ['label' => 'Potential savings', 'value' => '$'.number_format($stats['potentialSavings'], 2)],
    ])
    @foreach ($cells as $cell)
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $cell['label'] }}</div>
            <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $cell['value'] }}</div>
        </div>
    @endforeach
</div>
