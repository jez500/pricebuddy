<?php

namespace App\Filament\Resources\ProductSourceResource\Widgets;

use App\Models\ProductSource;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ProductSourceScrapeDebugWidget extends Widget
{
    public Model|ProductSource|null $record = null;

    public ?string $searchQuery = null;

    public ?ProductSource $productSource = null;

    protected static string $view = 'filament.resources.product-source-resource.widgets.scrape-debug-widget';

    public static function canView(): bool
    {
        return true;
        // return session()->has('test_scrape');
    }

    protected function getViewData(): array
    {

        return [
            'scrape' => array_merge(
                $this->productSource->searchDebugData($this->searchQuery),
                ['searchQuery' => $this->searchQuery]
            ),
        ];
    }
}
