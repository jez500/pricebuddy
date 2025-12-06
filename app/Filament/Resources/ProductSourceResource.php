<?php

namespace App\Filament\Resources;

use App\Enums\Icons;
use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Filament\Concerns\HasScraperTrait;
use App\Filament\Resources\ProductSourceResource\Pages;
use App\Models\ProductSource;
use App\Rules\ContainsSearchTermPlaceholder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductSourceResource extends Resource
{
    use HasScraperTrait;

    protected static ?string $model = ProductSource::class;

    protected static ?string $navigationLabel = 'Source';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basics')->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->hintIcon(Icons::Help->value, 'The name of the source, e.g. Deals-r-us, Amazon, etc.')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('search_url')
                        ->required()
                        ->rules([new ContainsSearchTermPlaceholder])
                        ->hintIcon(Icons::Help->value, 'The URL to search for products, substitute :search_term for the search term'),
                    Forms\Components\Select::make('type')
                        ->options(ProductSourceType::class)
                        ->hintIcon(Icons::Help->value, 'A deals site aggregates products from multiple sites, an online store sells products')
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('store_id')
                        ->label('Associated Store')
                        ->relationship('store', 'name')
                        ->visible(fn (Get $get) => $get('type') === ProductSourceType::OnlineStore->value)
                        ->hintIcon(Icons::Help->value, 'Link to existing store for scraping product pages'),
                    Forms\Components\Select::make('status')
                        ->required()
                        ->options(ProductSourceStatus::class)
                        ->default(ProductSourceStatus::Active),
                    Forms\Components\Select::make('weight')
                        ->label('Priority order')
                        ->hintIcon(Icons::Help->value, 'The lower the number, the higher priority')
                        ->default(0)
                        ->options(collect(range(-50, 50))->mapWithKeys(fn ($value) => [strval($value) => strval($value)])->all()),

                ])->columns(2)
                    ->description(__('A product source is a website that you can use to search for products'))
                    ->live(),

                Forms\Components\Group::make([
                    Forms\Components\Section::make('Search result item strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('list_container'))->columns(2),
                    ])->description('Wrapper for a single search result'),
                    Forms\Components\Section::make('Product title')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('product_title'))->columns(2),
                    ])->description('Title within the search result item'),
                    Forms\Components\Section::make('Product url')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('product_url'))->columns(2),
                    ])->description('Product URL within the search result item'),
                ])
                    ->columnSpanFull()
                    ->label('Extraction strategy')
                    ->statePath('extraction_strategy'),

                self::getScraperSettings(),

                Forms\Components\Section::make('Notes')->schema([
                    Forms\Components\RichEditor::make('notes')
                        ->hiddenLabel(true),
                ])->description('Additional notes regarding this source and how to scrape its content'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->searchable()
                        ->weight(FontWeight::Bold)
                        ->description(fn (ProductSource $record) => $record->type_label)
                        ->sortable(),
                    Tables\Columns\TextColumn::make('store.name')
                        ->grow(false)
                        ->extraAttributes(['class' => 'min-w-36 md:flex md:justify-start pr-4'])
                        ->sortable(),
                    Tables\Columns\TextColumn::make('status')
                        ->grow(false)
                        ->badge()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('user.name')
                        ->grow(false)
                        ->sortable(),
                ])->from('sm'),
            ])
            ->recordUrl(fn (ProductSource $record): string => self::getUrl('search', ['record' => $record]))
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ProductSourceStatus::class),
                Tables\Filters\SelectFilter::make('type')
                    ->options(ProductSourceType::class),
            ])
            ->actions([
                Tables\Actions\Action::make('search')
                    ->label('Search')
                    ->icon('heroicon-o-magnifying-glass')
                    ->url(fn (ProductSource $record): string => self::getUrl('search', ['record' => $record])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->currentUser()->with(['store']);
            });
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductSources::route('/'),
            'create' => Pages\CreateProductSource::route('/create'),
            'edit' => Pages\EditProductSource::route('/{record}/edit'),
            'search' => Pages\SearchProductSource::route('/{record}/search/{search?}'),
        ];
    }
}
