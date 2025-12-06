@php
    use App\Enums\Trend;
@endphp

<div
    class="product-card-column w-full" x-data="{ expanded: false }"
    style="{{ Filament\Support\get_color_css_variables(Trend::getColor($product->trend), shades: [300, 500, 400, 600, 800]) }}"
>
    @livewire('product-card-detail', ['product' => $product, 'standalone' => $standalone, 'showChart' => true], key('product-card-detail-column-'.$product->id))
</div>
