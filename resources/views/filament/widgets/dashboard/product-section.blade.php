@if ($products->isNotEmpty())
    <div class="mb-8" wire:key="section-{{ $sectionKey }}">
        <h3 class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            <x-filament::icon :icon="$icon" class="h-5 w-5 text-gray-400 dark:text-gray-600" />
            {{ $heading }}
        </h3>
        <div class="grid gap-6 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($products as $product)
                <div wire:key="{{ $sectionKey }}-{{ $product->id }}">
                    <x-product-card :product="$product" />
                </div>
            @endforeach
        </div>
    </div>
@endif
