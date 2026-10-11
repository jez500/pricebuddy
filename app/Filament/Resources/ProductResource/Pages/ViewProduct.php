<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * @property Product $record
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected static string $view = 'filament.resources.product-resource.pages.view';

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    /**
     * Scraped titles run long, so clamp the heading to a couple of lines rather than letting
     * it push the header actions down the page. The full title stays available on hover.
     */
    public function getHeading(): string|Htmlable
    {
        return new HtmlString(sprintf(
            '<span class="block max-w-3xl line-clamp-2 md:line-clamp-3" title="%s">%s</span>',
            e($this->record->title),
            e($this->record->title),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductResource\Actions\AddUrlAction::make(),
            ProductResource\Actions\FetchAction::make(),
            BaseAction::make('edit_product')->icon(Icons::Edit->value)
                ->label(__('Edit'))
                ->resourceName('product')
                ->resourceUrl('edit', $this->record),
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
