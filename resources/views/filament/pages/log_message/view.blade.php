<x-filament-panels::page class="fi-dashboard-page" xmlns:x-filament="http://www.w3.org/1999/html">
    @foreach(data_get($record, 'context', []) as $key => $val)
        <div class="mb-2">
            <x-filament::section :heading="$key">
                <x-slot name="headerEnd">
                    <x-filament::icon-button
                        class="copy-to-clipboard"
                        data-clipboard-target="#log-context-{{ $loop->index }}"
                        data-copy-notify="1"
                        icon="heroicon-m-clipboard-document"
                        color="gray"
                        size="sm"
                        label="Copy"
                        tooltip="Copy"
                    />
                </x-slot>
                <code id="log-context-{{ $loop->index }}" class="block whitespace-pre overflow-x-auto">{{ is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT) }}</code>
            </x-filament::section>
        </div>
    @endforeach
</x-filament-panels::page>


