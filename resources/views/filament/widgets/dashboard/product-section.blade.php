@if ($products->isNotEmpty())
    <div class="mb-8" wire:key="section-{{ $sectionKey }}">
        <h3 class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            <x-filament::icon :icon="$icon" class="h-5 w-5 text-gray-400 dark:text-gray-600" />
            {{ $heading }}
            <button type="button" wire:click="toggleSection('{{ $sectionKey }}')" class="ml-auto text-xs text-gray-400 hover:text-gray-600">
                Hide
            </button>
        </h3>
        <div class="grid gap-6 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($products as $product)
                @php
                    $verdict = data_get($product->insights_cache, 'dealScore.verdict');
                    $verdictKey = data_get($product->insights_cache, 'dealScore.verdictKey');
                    $lowConfidence = (bool) data_get($product->insights_cache, 'dealScore.lowConfidence', false);
                    $verdictColor = match ($verdictKey) {
                        'great', 'good' => 'text-primary-600 dark:text-primary-400',
                        'average' => 'text-gray-600 dark:text-gray-300',
                        'pricey' => 'text-amber-600 dark:text-amber-400',
                        'wait' => 'text-danger-600 dark:text-danger-400',
                        default => 'text-primary-600 dark:text-primary-400',
                    };
                @endphp
                <div wire:key="{{ $sectionKey }}-{{ $product->id }}">
                    @if ($verdict)
                        <div class="mb-1 flex items-center gap-2">
                            <span class="text-sm font-semibold {{ $verdictColor }}">{{ $verdict }}</span>
                            @if ($lowConfidence)
                                @include('components.icon-badge', [
                                    'hoverText' => __('Not enough price history for a confident verdict'),
                                    'label' => __('Low confidence'),
                                    'color' => 'gray',
                                    'icon' => 'heroicon-m-question-mark-circle',
                                ])
                            @endif
                        </div>
                    @endif
                    <x-product-card :product="$product" />
                </div>
            @endforeach
        </div>
    </div>
@endif
