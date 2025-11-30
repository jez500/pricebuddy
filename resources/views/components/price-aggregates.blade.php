@php
    use App\Enums\Trend;
    use function Filament\Support\get_color_css_variables;
    $hideTrend ?? $hideTrend = false;
@endphp
<div
    class="py-2 px-4 gap-2 flex"
    @style([
        get_color_css_variables(
            Trend::getColor($trend),
            shades: [50, 400, 500],
            alias: 'widgets::stats-overview-widget.stat.chart',
        ),
    ])
>
    @foreach (['min', 'avg', 'max'] as $agg)
        @if (isset($aggregates[$agg]))
            <div class="text-xs text-gray-500 dark:text-gray-400 pr-2">
                {{ ucfirst($agg) }}: {{ $aggregates[$agg] }}
            </div>
        @endif
    @endforeach
    @if (! $hideTrend)
            <x-filament::icon
                :icon="Trend::getIcon($trend)"
                class="ml-auto w-4 text-custom-600 dark:text-custom-400"
                title="Current price is {{ strtolower(Trend::getText($trend)) }}"
            />
    @endif
</div>
