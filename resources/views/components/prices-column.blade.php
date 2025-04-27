@php
    use App\Dto\PriceCacheDto;
    use Filament\Support\Colors\Color;

    if (empty($items) && isset($getState) && is_callable($getState)) {
        $items = $getState();
    }
@endphp
@if ($items)
    <ul class="my-2 text-sm min-w-0 max-w-md w-64">
        @foreach($items as $idx => $price)
            @php
                $cache = PriceCacheDto::fromArray($price);
                $color = 'text-custom-600 dark:text-custom-400';
            @endphp
            <li class="my-1" style="{{ Filament\Support\get_color_css_variables($cache->getTrendColor(), shades: [300, 500, 400, 600, 800]) }}">

                <a href="{{ $cache->getUrl() }}" target="_blank" class="flex gap-2 {{ $idx === 0 ? 'font-bold' : '' }}">

                    <x-filament::icon :icon="$cache->getTrendIcon()" class="w-4 {{ $color }}"/>

                    <div class="hover:underline {{ $color }}" @if ($idx > 0) style="{{ Filament\Support\get_color_css_variables(Color::Gray, shades: [300, 500, 400, 600, 800]) }}" @endif>
                        {{ $cache->getPriceFormatted()}}
                        ({{ $cache->getStoreName() }})
                    </div>

                </a>

            </li>
        @endforeach
    </ul>
@endif

