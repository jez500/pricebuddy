<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ProductResource\Actions\AddSearchResultUrlBulkAction;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\UrlResearch;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\IntegrationHelper;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CreateViaSearchTable extends BaseWidget
{
    public const int DEFAULT_PAGINATION = 100;

    protected $listeners = [
        'updateCreateViaSearchTable' => 'reRenderTable',
        'emptyCreateViaSearchTable' => 'emptyRenderTable',
    ];

    public ?string $searchQuery = null;

    public array $filters = [];

    public ?Product $product = null;

    public ?ProductSource $productSource = null;

    public static function canView(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $settings = IntegrationHelper::getSearchSettings();
        $prefix = data_get($settings, 'search_prefix');

        $query = UrlResearch::query();

        if (!empty($this->searchQuery)) {
            $query->searchQuery($this->searchQuery, $this->productSource);
        }

        $query->orderByRaw('ISNULL(price), price ASC')
            ->orderByDesc('store_id')
            ->orderByDesc('id');

        return $table
            ->heading('Search results for "'.e(($prefix ? $prefix.' ' : '').$this->searchQuery).'"')
            ->description('Select the results you want to add to '.($this->product ? '"'.$this->product->title.'"' : 'a new product'))
            ->query($query)
            ->columns(self::tableColumns())
            ->recordClasses(fn (UrlResearch $record) => empty($record->price) ? 'opacity-50' : '')
            ->filters([
                Filter::make('min')
                    ->label('Min price')
                    ->form([
                        TextInput::make('min_price')->numeric()->nullable()->placeholder('0')->default('0'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $query->setFilters($data);
                    }),
                Filter::make('max')
                    ->label('Max price')
                    ->form([
                        TextInput::make('max_price')->numeric()->nullable()->placeholder('No limit'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $query->setFilters($data);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultPaginationPageOption(self::DEFAULT_PAGINATION)
            ->bulkActions([
                AddSearchResultUrlBulkAction::make()->withSearchQuery($this->searchQuery)
                    ->withProduct($this->product),
            ])
            ->emptyStateHeading(fn () => 'No results found for "'.$this->searchQuery.'"')
            ->emptyStateDescription(fn () => 'Unable to parse any prices from results')
            ->checkIfRecordIsSelectableUsing(fn (UrlResearch $record): bool => ! empty($record->price));
    }

    public function reRenderTable(?string $searchQuery): void
    {
        $this->searchQuery = $searchQuery;

        $this->resetTable();
    }

    public function emptyRenderTable(): void
    {
        $this->searchQuery = null;

        $this->resetTable();
    }

    public static function tableColumns(): array
    {
        return [
            Tables\Columns\Layout\Split::make([

                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('title')
                        ->label('Title')
                        ->weight(FontWeight::Bold)
                        ->url(fn (UrlResearch $record) => $record->url),
                    Tables\Columns\TextColumn::make('url')
                        ->label('Url')
                        ->color('gray')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<a href="'.$state.'" title="'.$state.'" target="_blank">'.Str::limit($state, 80).'</a>')
                        ),
                ])->extraAttributes(['class' => 'w-xl']),

                Tables\Columns\Layout\Stack::make([

                    Tables\Columns\TextColumn::make('price')
                        ->label('Price')
                        ->weight(FontWeight::Bold)
                        ->formatStateUsing(fn (?float $state, UrlResearch $record): HtmlString => new HtmlString(
                            empty($state) ? 'No price' : CurrencyHelper::toString($state, locale: $record->store?->locale, iso: $record->store?->currency)
                        ))
                        ->extraAttributes(['class' => 'md:text-right md:justify-end']),

                    Tables\Columns\TextColumn::make('store.name')
                        ->label('Store')
                        ->color('gray')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                            $state ?: 'Add store'
                        ))
                        ->extraAttributes(['class' => 'md:text-right md:justify-end']),

                ])->extraAttributes(['class' => 'md:w-sm md:align-right md:pr-8'])->grow(false),

            ])->from('sm'),

        ];
    }

    public function refreshTable(): void
    {
        $this->getTableRecords()->fresh();
        $this->resetTable();
    }
}
