@php
    /** @var \App\Models\Product $product */
    $nextCheck = $product->nextCheckEstimate();
    $periodStart = $product->currentCheckPeriodStart();
@endphp

@if ($nextCheck && $periodStart)
    <div
        class="px-4 pb-2 pt-1"
        x-data="{
            start: {{ $periodStart->getTimestamp() }},
            next: {{ $nextCheck->getTimestamp() }},
            progress: 0,
            label: '',
            tick() {
                const now = Math.floor(Date.now() / 1000);
                const total = this.next - this.start;
                const elapsed = now - this.start;
                this.progress = total <= 0 ? 100 : Math.min(100, Math.max(0, (elapsed / total) * 100));

                let remaining = this.next - now;
                if (remaining <= 0) {
                    this.progress = 100;
                    this.label = @js(__('due'));
                    return;
                }
                const d = Math.floor(remaining / 86400);
                const h = Math.floor((remaining % 86400) / 3600);
                const m = Math.floor((remaining % 3600) / 60);
                const s = remaining % 60;
                this.label = d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${s}s` : `${s}s`;
            },
            init() {
                this.tick();
                setInterval(() => this.tick(), 1000);
            },
        }"
    >
        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
            <span>{{ __('Next check') }}</span>
            <span x-text="label" class="tabular-nums"></span>
        </div>
        <div class="h-1 w-full rounded-full bg-custom-400/10 overflow-hidden">
            <div
                class="h-full rounded-full bg-custom-500 transition-[width] duration-1000 ease-linear"
                :style="`width: ${progress}%`"
            ></div>
        </div>
    </div>
@elseif ($product->paused)
    <div class="px-4 pb-2 pt-1">
        <div class="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500 mb-1">
            <span>{{ __('Next check') }}</span>
            <span>{{ __('Paused') }}</span>
        </div>
        <div class="h-1 w-full rounded-full bg-gray-200 dark:bg-gray-700"></div>
    </div>
@endif
