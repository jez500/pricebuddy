<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use App\Models\Url;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @property Product $record
 */
class PriceHistoryChart extends ChartWidget
{
    const CHART_COLORS = [
        AdminPanelProvider::PRIMARY_COLOR,
        Color::Pink,
        Color::Yellow,
        Color::Purple,
        Color::Emerald,
        Color::Violet,
        Color::Sky,
        Color::Amber,
        Color::Blue,
    ];

    public Model|Product|null $record = null;

    protected static ?string $maxHeight = '300px';

    protected static ?string $heading = 'Store price history';

    protected static ?string $pollingInterval = null;

    public ?string $filter = 'unit_price';

    protected function getFilters(): ?array
    {
        return [
            'unit_price' => 'Unit Price',
            'retail_price' => 'Retail Price',
        ];
    }

    protected function getData(): array
    {
        $showUnitPrice = $this->filter === 'unit_price';
        $history = $this->record->getPriceHistoryCached($showUnitPrice ? 'unit_price' : 'price');

        $datasets = [];

        $urls = Url::findMany($history->keys())->values();

        foreach ($urls as $idx => $url) {
            $data = $history->get($url->id);

            $datasets[] = [
                'label' => $url->store?->name,
                'data' => $data,
                'backgroundColor' => 'rgba('.$this->getDatasetColor($idx).', 0.4)',
                'borderColor' => 'rgba('.$this->getDatasetColor($idx).', 0.9)',
                'fill' => true,
                'tension' => 0.2,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $this->getLabels($history),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'type' => 'timeseries',
                    'ticks' => [
                        'stepSize' => 5,
                    ],
                    'time' => [
                        'unit' => 'day',
                    ],
                ],
                'y' => [
                    'type' => 'linear',
                    'ticks' => [
                        'count' => 5,
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getLabels(Collection $history): array
    {
        return $history->map(fn ($prices) => $prices->keys())
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    protected function getDatasetColor(int $idx)
    {
        if (isset(self::CHART_COLORS[$idx])) {
            return self::CHART_COLORS[$idx][500];
        } else {
            return Color::Gray[500];
        }
    }
}
