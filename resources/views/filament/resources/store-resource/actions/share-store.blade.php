@php
$export = json_encode(collect($record->toArray())
    ->only([
        'name',
        'slug',
        'domains',
        'scrape_strategy',
        'settings',
    ])->toArray(), JSON_PRETTY_PRINT) ;
@endphp
<div>
    <div x-data="{ tab: 'export' }">
        {{-- Tabs-- @todo when more share methods available }}
        {{--        <x-filament::tabs label="Share tabs" class="justify-stretch sm:justify-start rounded-bl-none rounded-br-none">--}}
        {{--            <x-filament::tabs.item @click="tab = 'export'" :alpine-active="'tab === \'export\''"--}}
        {{--                                   class="w-full sm:w-auto">--}}
        {{--                <div class="flex align-center gap-2">--}}
        {{--                    <x-filament::icon icon="heroicon-o-arrow-up-on-square" class="w-4"/>--}}
        {{--                    {{ __('Export') }}--}}
        {{--                </div>--}}
        {{--            </x-filament::tabs.item>--}}
        {{--        </x-filament::tabs>--}}

        <div class="share-content">
            <div x-show="tab === 'export'" x-data="{'copied': false}">
                <pre id="export-content" class="text-xs p-4 rounded bg-gray-100 dark:bg-gray-800/30 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10"><code>{{ $export }}</code></pre>
                <x-filament::button class="copy-to-clipboard mt-6" data-clipboard-target="#export-content" @click="copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="! copied">{{ __('Copy to clipboard') }}</span>
                    <span x-show="copied">{{ __('Copied!') }}</span>
                </x-filament::button>
            </div>
        </div>
    </div>
</div>
