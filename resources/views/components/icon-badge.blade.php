@php
    use function Filament\Support\get_color_css_variables;
    $hoverText = $hoverText ?? null;
    $label = $label ?? null;
    $color = $color ?? 'warning';
    $icon = $icon ?? 'heroicon-m-exclamation-triangle';
@endphp
<span
    title="{{ $hoverText }}"
    style="{{ get_color_css_variables(
        $color,
        shades: [50, 400, 600],
    ) }}"
    class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30"
>
    <x-filament::icon
        icon="{{ $icon }}"
        class="text-custom-600 dark:text-custom-400 w-4 h-4"
    />
    @if($label)
        <span>{{ $label }}</span>
    @endif
</span>
