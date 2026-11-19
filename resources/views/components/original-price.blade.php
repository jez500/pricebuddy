@props(['cache', 'unit' => true, 'brackets' => false])
@php
    /** @var \App\Dto\PriceCacheDto $cache */
@endphp
@if ($cache->isConverted() && $cache->hasVisiblePrice())
    <span
        {{ $attributes->merge(['class' => 'pb-original-price text-xs font-normal text-gray-500 dark:text-gray-400 whitespace-nowrap']) }}
        title="{{ $cache->getConversionDescription() }}"
    >{{ $brackets ? '(' : '' }}{{ $unit ? $cache->getOriginalUnitPriceFormatted() : $cache->getOriginalPriceFormatted() }}{{ $brackets ? ')' : '' }}</span>
@endif
