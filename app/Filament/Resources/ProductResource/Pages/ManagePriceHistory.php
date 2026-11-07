<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Services\PriceHistoryCleanup;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * @property Form $filtersForm
 *
 * @method Product getRecord()
 */
class ManagePriceHistory extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProductResource::class;

    protected static string $view = 'filament.resources.product-resource.pages.price-history';

    /** @var array<string, mixed> */
    public array $filters = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $appliedFilters = [];

    #[Locked]
    public ?string $pendingDeletion = null;

    #[Locked]
    public int $pendingDeletionCount = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        Gate::authorize('update', $this->getRecord());
        $this->filtersForm->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Price history');
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function getForms(): array
    {
        return ['filtersForm'];
    }

    public function filtersForm(Form $form): Form
    {
        return $form->statePath('filters')->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
            Select::make('listing_id')->label(__('Listing'))
                ->options(fn (): array => $this->listingOptions())
                ->placeholder(__('All listings'))
                ->live()->columnSpanFull()
                ->required(fn (Get $get): bool => filled($get('min_price')) || filled($get('max_price'))),
            DatePicker::make('from')->label(__('From date'))->native(false),
            DatePicker::make('until')->label(__('Through date'))->native(false),
            TextInput::make('min_price')->label(__('Minimum recorded price'))->numeric()->minValue(0)
                ->suffix(fn (Get $get): string => $this->listingCurrency($get('listing_id'))),
            TextInput::make('max_price')->label(__('Maximum recorded price'))->numeric()->minValue(0)
                ->suffix(fn (Get $get): string => $this->listingCurrency($get('listing_id'))),
        ]);
    }

    public function applyFilters(): void
    {
        Gate::authorize('update', $this->getRecord());
        try {
            $this->appliedFilters = app(PriceHistoryCleanup::class)->validateFilters($this->filtersForm->getState());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key): array => [str_starts_with($key, 'filters.') ? $key : 'filters.'.$key => $messages])->all());
        }
        $this->deselectAllTableRecords();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function clearFilters(): void
    {
        $this->filtersForm->fill();
        $this->appliedFilters = [];
        $this->resetErrorBag();
        $this->deselectAllTableRecords();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(PriceHistoryCleanup::class)->query($this->actor(), $this->getRecord(), $this->appliedFilters)->with(['store', 'url']))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('No matching price records'))
            ->emptyStateDescription(__('Future price checks will appear here. Clear the filters to see other records.'))
            ->columns([
                TextColumn::make('created_at')->label(__('Recorded'))->dateTime('M j, Y H:i:s')->sortable(),
                TextColumn::make('store.name')->label(__('Store'))->wrap()->placeholder(__('Unknown store')),
                TextColumn::make('url.url')->label(__('Listing'))->limit(45)->tooltip(fn (Price $record): ?string => $record->url?->url)->wrap(),
                TextColumn::make('store.currency')->label(__('Currency'))->placeholder(__('Unknown')),
                TextColumn::make('price')->label(__('Recorded price'))->alignEnd()->extraAttributes(['class' => 'tabular-nums'])
                    ->formatStateUsing(fn (Price $record): string => $this->formatPrice($record, 'price')),
                TextColumn::make('unit_price')->label(__('Unit price'))->alignEnd()->extraAttributes(['class' => 'tabular-nums'])
                    ->formatStateUsing(fn (Price $record): string => $this->formatPrice($record, 'unit_price')),
            ])
            ->actions([
                TableAction::make('delete')->label(__('Delete'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->mountUsing(fn (Price $record) => $this->prepareDeletion([$record->getKey()]))
                    ->modalHeading(__('Delete price record?'))
                    ->modalDescription(__('This permanently removes the selected reading and recalculates the chart and statistics. The tracked URL is kept.'))
                    ->modalSubmitActionLabel(__('Delete record'))
                    ->action(fn () => $this->deleteConfirmed()),
            ])
            ->bulkActions([
                BulkAction::make('delete')->label(__('Delete selected'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->fetchSelectedRecords(false)
                    ->mountUsing(fn (Collection $records) => $this->prepareDeletion($records->all()))
                    ->modalHeading(fn (): string => __('Delete :count price records?', ['count' => $this->pendingDeletionCount]))
                    ->modalDescription(__('This permanently removes only the confirmed records and recalculates the chart and statistics. Tracked URLs are kept.'))
                    ->modalSubmitActionLabel(__('Delete records'))
                    ->deselectRecordsAfterCompletion()
                    ->action(fn () => $this->deleteConfirmed()),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('view')->label(__('Back to product'))->url(ProductResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** @return array<int, string> */
    protected function listingOptions(): array
    {
        $options = [];
        foreach ($this->getRecord()->urls()->with('store')->get() as $url) {
            /** @var Url $url */
            if ($url->store) {
                $options[$url->getKey()] = $url->store->name.' ('.$url->store->currency.') — '.Str::limit($url->url, 80);
            }
        }

        return $options;
    }

    protected function listingCurrency(mixed $id): string
    {
        /** @var ?Url $url */
        $url = filled($id) ? $this->getRecord()->urls()->with('store')->whereKey($id)->first() : null;

        return $url?->store->currency ?? '';
    }

    protected function formatPrice(Price $price, string $field): string
    {
        if (! $price->store) {
            return (string) $price->{$field};
        }

        return Number::currency((float) $price->{$field}, in: $price->store->currency, locale: $price->store->locale);
    }

    /** @param array<int, int|string> $ids */
    protected function prepareDeletion(array $ids): void
    {
        $this->pendingDeletion = null;
        $this->pendingDeletionCount = 0;
        try {
            $this->pendingDeletion = app(PriceHistoryCleanup::class)->snapshot($this->actor(), $this->getRecord(), $ids);
            $this->pendingDeletionCount = count($ids);
        } catch (ValidationException $exception) {
            Notification::make()->title(__('Select the records again'))->body(collect($exception->errors())->flatten()->first())->danger()->send();
            throw new Cancel;
        }
    }

    protected function deleteConfirmed(): void
    {
        try {
            $count = app(PriceHistoryCleanup::class)->deleteSnapshot($this->actor(), $this->getRecord(), $this->pendingDeletion ?? '');
        } catch (ValidationException $exception) {
            Notification::make()->title(__('Select the records again'))->body(collect($exception->errors())->flatten()->first())->warning()->send();
            throw new Halt;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title(__('Could not delete price records'))->body(__('Nothing was removed. Refresh the history and try again.'))->danger()->send();
            throw new Halt;
        }
        $this->pendingDeletion = null;
        $this->pendingDeletionCount = 0;
        $this->record->refresh();
        $this->resetPage();
        $this->flushCachedTableRecords();
        Notification::make()->title($count === 1 ? __('Price record deleted') : __(':count price records deleted', ['count' => $count]))->success()->send();
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
