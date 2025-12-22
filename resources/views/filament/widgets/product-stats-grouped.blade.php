@php
    use App\Filament\Widgets\NoProductsFound;
@endphp
<x-filament-widgets::widget>
    @if (empty($groups))
        @livewire(NoProductsFound::class)
    @else
        @foreach ($groups as $group)
            <div class="mb-8">
                <h3 class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-s-tag" class="h-5 w-5 pt-1 text-gray-400 dark:text-gray-600" />
                    {{ $group['heading'] }}
                </h3>
                <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($group['products'] as $product)
                        <div>
                            <x-product-card :product="$product" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</x-filament-widgets::widget>
