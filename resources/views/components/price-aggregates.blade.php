@php
    use App\Enums\Trend;
    use Carbon\CarbonInterface;
    use Illuminate\Support\Carbon;
    use function Filament\Support\get_color_css_variables;
    $hideTrend ?? $hideTrend = true;
    $age = isset($age) ? Carbon::parse($age) : null;
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
    @if ($age)
        <div class="text-xs text-gray-500 dark:text-gray-400 pr-2" title="First price on: {{ $age->toDateString() }}">
            Age: {{ $age->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true) }}
        </div>
    @endif
    @if (! $hideTrend)
        <x-filament::icon
            :icon="Trend::getIcon($trend)"
            class="ml-auto w-4 text-custom-600 dark:text-custom-400"
            title="Current price is {{ strtolower(Trend::getText($trend)) }}"
        />
    @endif
</div>
