<x-filament-widgets::widget>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        @foreach($scrape as $key => $val)
            @if ($key !== 'store')
                <div class="mb-8">
                    <x-filament::section :heading="$key">
                        <code class="block whitespace-pre overflow-x-auto">{{ is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT) }}</code>
                    </x-filament::section>
                </div>
            @endif
        @endforeach
    @endif
</x-filament-widgets::widget>
