@props(['text' => null])
{{-- Marks a price that is not the store's page price: the store adds tax or shipping.
     Hover or tap shows what the price is made of; screen readers get it from the label.
     Prices often sit inside a link, where a focusable element is not allowed: there,
     tabbing to the link shows the breakdown, and a tap shows it instead of following the
     link. Outside a link the marker itself can be focused. --}}
@if (filled($text))<span
    x-data="{}"
    x-tooltip="{ content: @js($text), theme: $store.theme, hideOnClick: false }"
    x-init="
        const link = $el.closest('a, button');
        if (! link) {
            $el.tabIndex = 0;
        } else {
            link.addEventListener('focus', () => link.matches(':focus-visible') && $el._tippy?.show());
            link.addEventListener('blur', () => $el._tippy?.hide());
        }
    "
    x-on:click.prevent.stop
    role="img"
    aria-label="{{ $text }}"
    class="pb-price-breakdown cursor-help"
>*</span>@endif
