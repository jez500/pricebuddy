@php
    use Carbon\Carbon;
    $messageList = collect($messages)->reverse()->values();
@endphp
<div>
    <x-filament::section
        class="my-6"
        collapsible
        :collapsed="! empty($complete)"
    >
        @foreach($messageList as $idx => $log)
            @if ($idx === 0)
                <x-slot name="icon">
                    @if($complete)
                        @php
                            $headlineIcon = data_get($log, 'data.icon', 'heroicon-o-check-circle');
                            $isWarning = $headlineIcon === \App\Enums\Icons::Warning->value;
                        @endphp
                        <x-filament::icon
                            :icon="$isWarning ? \App\Enums\Icons::Warning->value : 'heroicon-o-check-circle'"
                            class="h-6 w-6 -ml-1"
                            @style([
                                'color: rgb(var(--warning-500))' => $isWarning,
                                'color: rgb(var(--success-500))' => ! $isWarning,
                            ])
                        />
                    @else
                        <x-filament::loading-indicator class="h-6 w-6 -ml-1"/>
                    @endif
                </x-slot>

                <x-slot name="heading">
                    <div class="flex flex-col md:flex-row md:items-start gap-2 -ml-1">

                        <div class="flex-1">
                            <div class="font-bold">{{ str()->of($log['message'])->limit() }}</div>
                            <div
                                class="fi-section-header-description overflow-hidden break-words text-sm text-gray-500 dark:text-gray-400 mt-1">
                                @if(isset($log['data']['subtitle']))
                                    <a class="font-normal break-all" href="{{ $log['data']['subtitle'] }}"
                                       target="_blank">
                                        {{ str()->of(data_get($log, 'data.subtitle', ''))->limit() }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-slot>
            @else
                @php
                    $previousTimestamp = $messageList[$idx - 1]['timestamp'] ?? null;
                    $duration = $previousTimestamp && isset($log['timestamp'])
                        ? Carbon::parse($log['timestamp'])->diffInSeconds($previousTimestamp).' sec'
                        : null;
                    $icon = data_get($log, 'data.icon', 'heroicon-o-check-circle');
                @endphp
                <div
                    class="text-sm text-gray-500 dark:text-gray-300 flex md:items-center py-3 md:py-1 border-t border-gray-300 dark:border-gray-800 first:border-t-0">
                    <x-filament::icon
                        :icon="$icon"
                        class="w-5 h-5 min-w-4 mr-2"
                        @style([
                            'color: rgb(var(--success-500))' => $icon === \App\Enums\Icons::Success->value,
                            'color: rgb(var(--warning-500))' => $icon === \App\Enums\Icons::Warning->value,
                            'color: rgb(var(--info-500))' => $icon === \App\Enums\Icons::Database->value,
                        ])
                    />
                    <div class="flex flex-col md:flex-row md:justify-between gap-2 flex-1">
                        <div class="md:flex-1">{{ str()->of($log['message'])->limit() }}</div>
                        @if(isset($log['timestamp']))
                            <div class="md:ml-auto text-xs font-normal text-gray-400 dark:text-gray-400">
                                {{ $duration }}
                            </div>
                        @endif
                    </div>

                </div>
            @endif
        @endforeach
    </x-filament::section>
</div>
