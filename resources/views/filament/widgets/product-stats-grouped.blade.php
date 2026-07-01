@php
    use App\Filament\Widgets\NoProductsFound;
    $sectionLabels = ['stat_bar' => 'Summary stats', 'buy_now' => "What's good to buy now", 'recently_dropped' => 'Recently dropped', 'needs_attention' => 'Needs attention'];
@endphp
<x-filament-widgets::widget>
    <x-filament::dropdown placement="bottom-start" class="mb-4">
        <x-slot name="trigger">
            <x-filament::button size="sm" color="gray" icon="heroicon-m-adjustments-horizontal">Customize</x-filament::button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($sections as $section)
                <x-filament::dropdown.list.item wire:click="toggleSection('{{ $section['key'] }}')" wire:key="toggle-{{ $section['key'] }}">
                    <span class="flex items-center gap-2">
                        <x-filament::icon :icon="$section['visible'] ? 'heroicon-s-eye' : 'heroicon-s-eye-slash'" class="h-4 w-4" />
                        {{ $sectionLabels[$section['key']] ?? $section['key'] }}
                    </span>
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    @foreach ($sections as $section)
        @continue (! $section['visible'])
        @if ($section['key'] === 'stat_bar')
            @include('filament.widgets.dashboard.stat-bar', ['stats' => $sectionData['stat_bar']])
        @elseif ($section['key'] === 'buy_now')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'buy_now', 'heading' => "What's good to buy now", 'icon' => 'heroicon-s-bolt', 'products' => $sectionData['buy_now']])
        @elseif ($section['key'] === 'recently_dropped')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'recently_dropped', 'heading' => 'Recently dropped', 'icon' => 'heroicon-s-arrow-trending-down', 'products' => $sectionData['recently_dropped']])
        @elseif ($section['key'] === 'needs_attention')
            @include('filament.widgets.dashboard.product-section', ['sectionKey' => 'needs_attention', 'heading' => 'Needs attention', 'icon' => 'heroicon-s-exclamation-triangle', 'products' => $sectionData['needs_attention']])
        @endif
    @endforeach

    @if (empty($groups))
        @livewire(NoProductsFound::class)
    @else
        <div
            x-sortable
            x-on:end.stop="$wire.reorderCategories($event.target.sortable.toArray())"
        >
            @foreach ($groups as $group)
                <div
                    class="mb-8"
                    wire:key="cat-{{ $group['signature'] }}"
                    data-category-signature="{{ $group['signature'] }}"
                    x-sortable-item="{{ $group['signature'] }}"
                >
                    <h3
                        x-sortable-handle
                        class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white cursor-ns-resize"
                    >
                        <x-filament::icon icon="heroicon-s-tag" class="h-5 w-5 text-gray-400 dark:text-gray-600" />
                        {{ $group['heading'] }}
                        <button type="button" wire:click="toggleCategoryCollapse('{{ $group['signature'] }}')" class="ml-auto text-gray-400">
                            <x-filament::icon :icon="$group['collapsed'] ? 'heroicon-s-chevron-right' : 'heroicon-s-chevron-down'" class="h-5 w-5" />
                        </button>
                    </h3>

                    <div
                        @class(['fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 2xl:grid-cols-3', 'hidden' => $group['collapsed']])
                        x-sortable
                        x-on:end.stop="$wire.reorderProducts($event.target.sortable.toArray())"
                    >
                        @foreach ($group['products'] as $product)
                            <div
                                wire:key="prod-{{ $product->id }}"
                                data-product-id="{{ $product->id }}"
                                x-sortable-item="{{ $product->id }}"
                            >
                                <x-product-card :product="$product" :draggable="true" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
