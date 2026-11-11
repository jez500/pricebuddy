@props(['text' => null])
{{-- Marks a price that is not the store's page price: the store adds tax or shipping.
     Hover or tap shows what the price is made of; screen readers get it from the label.
     Prices often sit inside a link, so a tap shows the breakdown instead of following
     it, and it is not focusable (a link may not contain one). --}}
@if (filled($text))<span
    x-data="{}"
    x-tooltip="{ content: @js($text), theme: $store.theme, hideOnClick: false }"
    x-on:click.prevent.stop
    role="img"
    aria-label="{{ $text }}"
    class="pb-price-breakdown cursor-help"
>*</span>@endif
