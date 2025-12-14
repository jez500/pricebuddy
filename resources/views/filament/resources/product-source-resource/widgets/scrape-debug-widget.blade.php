@php
    use App\Services\Helpers\CodeHelper;
    use Illuminate\Support\Str;
@endphp
<x-filament-widgets::widget>
    <div>
        <div>
            @if (empty($scrape))
                <p class="my-6">{{ __('Unable to find any data, check source settings') }}</p>
            @else
                <x-filament::section icon="heroicon-o-document-magnifying-glass">
                    <x-slot name="heading">
                        Debugging data
                    </x-slot>

                    <div>
                        @foreach($scrape as $key => $val)
                            <div class="mb-4" x-data="{ expanded: false }">
                                @if ($key === 'html')
                                    <h3 class="font-bold mb-2">Result page HTML response</h3>
                                    <div :class="expanded ? 'max-h-none overflow-y-visible' : 'max-h-[300px] overflow-y-hidden'">
                                        {!! CodeHelper::formatHtml($val) !!}
                                    </div>
                                <x-filament::button @click="expanded = !expanded" color="gray" class="w-full py-2">
                                    Show entire response
                                </x-filament::button>
                                @elseif (is_array($val))
                                    <h3 class="font-bold mb-2">First few results</h3>
                                    @foreach($val as $result)
                                        <div class="flex flex-col mb-6 gap-4 md:flex-row bg-gray-50 dark:bg-gray-800/80 rounded-md p-4">
                                            <div class="md:w-1/3">
                                                @foreach (['title', 'url'] as $itemKey)
                                                    <div class="mb-4">
                                                        <h3 class="font-bold mb-2">{{ Str::title($itemKey) }}</h3>
                                                        <div class="rounded-md break-all bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-black/10 dark:ring-white/10 py-2 px-4">{{ $result[$itemKey] }}</div>
                                                    </div>
                                                    @endforeach
                                            </div>
                                            <div class="md:w-2/3 max-h-[200px] overflow-y-auto">
                                                <h3 class="font-bold mb-2">HTML</h3>
                                                {!! CodeHelper::formatHtml(str_replace('>', '>'.PHP_EOL, $result['content'])) !!}
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    <h3 class="font-bold mb-2">{{ Str::title($key) }}</h3>
                                    <pre class="rounded-md bg-gray-50 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800/80 dark:ring-white/10 py-2 px-4">{{ $val }}</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>

                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
