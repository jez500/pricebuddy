@php
    $height = $height ?? '50px';
@endphp
<div>
    <div
        style="height: {{ $height }}"
        id="{{ uniqid('chart-wrapper-'.$product->getKey().'-') }}"
        x-load
        x-data="pbChart({
            cachedData: @js($cachedDatasets),
            options: @js($options),
            type: @js($type),
        })"
        {{ $attributes->merge(['class' => 'relative']) }}
    >
        <canvas
            role="img"
            aria-label="Price history chart for {{ $product->name ?? 'product' }}"
            class="absolute inset-0 top-1 bottom-1"
            x-ref="canvas"
            id="{{ uniqid('chart-canvas-'.$product->getKey().'-') }}"
        ></canvas>
    </div>
</div>

