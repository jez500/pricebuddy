@php
    use App\Dto\PriceCacheDto;
    use Filament\Support\Colors\Color;

    if (empty($items) && isset($getState) && is_callable($getState)) {
        $items = $getState();
    }
@endphp
@if ($items)
    <ul class="
        my-2
        mt-3
        text-sm
        min-w-0
        bg-white
        w-full
        shadow-sm
        ring-1
        ring-gray-950/5
        dark:bg-gray-900
        dark:ring-white/10
        rounded-md
    ">
        @foreach($items as $idx => $price)
            @php
                $cache = PriceCacheDto::fromArray($price);
                $color = 'text-custom-600 dark:text-custom-400';
            @endphp
            <li style="{{ Filament\Support\get_color_css_variables($cache->getTrendColor(), shades: [300, 500, 400, 600, 800]) }}">

                <a href="{{ $cache->getUrl() }}" target="_blank" class="
                    flex gap-2 {{ $idx === 0 ? 'font-bold' : '' }}
                    block
                    px-4 py-2
                    border-b
                    dark:border-b-white/5
                    hover:bg-gray-200/20
                    dark:hover:bg-gray-800/30
                ">

                    <x-filament::icon :icon="$cache->getTrendIcon()" class="w-4 {{ $color }}"/>

                    <div class="hover:underline {{ $color }}" @if ($idx > 0) style="{{ Filament\Support\get_color_css_variables(Color::Gray, shades: [300, 500, 400, 600, 800]) }}" @endif>
                        <strong class="text-[1.2em] font-bold">{{ $cache->getPriceFormatted() }}</strong>
                        ({{ $cache->getStoreName() }})
                    </div>

                </a>

            </li>
        @endforeach
    </ul>
@endif

