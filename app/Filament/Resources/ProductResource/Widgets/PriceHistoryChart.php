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

        $labels = $this->getLabels($history);

        // Daily best = lowest price across all stores per day, using the SAME
        // column ($history respects the unit/retail filter) so the line stays
        // consistent with the per-store datasets.
        $bestByDate = [];
        foreach ($history as $urlHistory) {
            foreach ($urlHistory as $date => $price) {
                if ($price <= 0) {
                    continue;
                }
                $bestByDate[$date] = isset($bestByDate[$date]) ? min($bestByDate[$date], $price) : $price;
            }
        }

        $datasets[] = [
            'label' => 'Best price',
            'data' => collect($labels)->map(fn (string $date) => $bestByDate[$date] ?? null)->all(),
            'borderColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[600].', 1)',
            'backgroundColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[600].', 0)',
            'borderWidth' => 3,
            'tension' => 0.2,
            'fill' => false,
            'spanGaps' => true,
        ];

        // Flat target line when a notify price is configured.
        if ($this->record->notify_price !== null) {
            $target = (float) $this->record->notify_price;
            $datasets[] = [
                'label' => 'Your target',
                'data' => array_fill(0, count($labels), $target),
                'borderColor' => 'rgba(239, 68, 68, 0.9)',
                'backgroundColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => 1,
                'borderDash' => [5, 5],
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
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
