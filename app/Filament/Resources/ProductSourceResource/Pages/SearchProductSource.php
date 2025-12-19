<?php

namespace App\Filament\Resources\ProductSourceResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource\Widgets\CreateViaSearchTable;
use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Widgets\ProductSourceScrapeDebugWidget;
use App\Jobs\CacheSearchResults;
use App\Models\ProductSource;
use App\Services\SearchService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @method ProductSource getRecord()
 */
class SearchProductSource extends EditRecord
{
    protected static string $resource = ProductSourceResource::class;

    protected static ?string $title = 'Search Product Source';

    protected static string $view = 'filament.resources.product-source-resource.pages.search-product-source';

    public ?string $searchQuery = null;

    public array $progressLog = [];

    public bool $showLog = false;

    public false|string $inProgress = false;

    public false|string $isComplete = false;

    protected $listeners = [
        'refreshProgress' => 'refreshProgress',
    ];

    public function getTitle(): string|Htmlable
    {
        return __('Search :source', ['source' => $this->record->name ?? 'Product Source']);
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->refresh();

        // Get search query from route parameter or session
        $this->searchQuery = request()->route('search') ?? session()->get('product_source_search_query');

        $this->form->fill([
            'search_query' => $this->searchQuery,
        ]);

        // Initialize progress log
        $this->progressLog[] = ['message' => __('Ready to search'), 'timestamp' => now()];

        // If we have a search query, trigger the search
        if ($this->searchQuery) {
            $this->executeSearch();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Search Configuration')
                    ->description('Search for products using this product source')
                    ->schema([
                        TextInput::make('search_query')
                            ->label('Search Query')
                            ->hintIcon(Icons::Help->value, 'Enter a search term to find products')
                            ->required()
                            ->placeholder('e.g., laptop, phone, etc.'),
                    ])
                    ->columns(1),
            ]);
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->authorizeAccess();

        $this->searchQuery = data_get($this->data, 'search_query', '');

        // Save search query to session
        session()->put('product_source_search_query', $this->searchQuery);

        // Execute the search
        $this->executeSearch();

        $this->redirect($this->getRedirectUrl());
    }

    protected function executeSearch(): void
    {
        if (empty($this->searchQuery)) {
            return;
        }

        $this->showLog = ! empty($this->searchQuery);

        // Avoid empty log
        if (empty($this->progressLog)) {
            $this->progressLog[] = ['message' => __('Preparing to search'), 'timestamp' => now()];
        }

        $source = $this->getRecord();

        $service = SearchService::new($this->searchQuery)->setProductSource($source);

        if ($inProgress = $service->getInProgress()) {
            $this->inProgress = $inProgress;

            Notification::make('searchJobAlreadyInProgress')
                ->title(__('Search job already in progress'))
                ->body(__('Started '.Carbon::parse($inProgress)->diffForHumans()))
                ->warning()
                ->send();
        }

        if ($isComplete = $service->getIsComplete()) {
            $this->isComplete = $isComplete;
        }

        if ($this->isComplete || $this->inProgress) {
            $this->refreshProgress();

            return;
        }

        $this->inProgress = now()->toDateTimeString();
        $this->progressLog[] = ['message' => __('Dispatching search job for ":query"', ['query' => $this->searchQuery]), 'timestamp' => now()];
        $this->isComplete = false;

        CacheSearchResults::dispatch($this->searchQuery, $source->id);

        Notification::make('searchJobDispatched')
            ->title(__('Search job dispatched'))
            ->success()
            ->send();
    }

    public function refreshProgress(): void
    {
        if ($this->searchQuery && ! $this->isComplete) {
            $this->progressLog[] = ['message' => __('Refreshing progress for ":query"', ['query' => $this->searchQuery]), 'timestamp' => now()];

            $service = SearchService::new($this->searchQuery)->setProductSource($this->getRecord());
            $this->progressLog = $service->getLog();
            $this->inProgress = $service->getInProgress();
            $this->isComplete = $service->getIsComplete();
        }
    }

    public function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('search')
            ->label('Search')
            ->icon('heroicon-m-magnifying-glass')
            ->submit('save')
            ->keyBindings(['mod+s']);
    }

    protected function getHeaderActions(): array
    {
        return [
            BaseAction::make('edit')->icon(Icons::Edit->value)
                ->resourceName('product-sources')
                ->resourceUrl('edit', $this->record)
                ->label(__('Edit')),
            Actions\DeleteAction::make()->icon(Icons::Delete->value),
        ];
    }

    protected function getRedirectUrl(): string
    {
        // Redirect back to same page with search query in URL
        return $this->getResource()::getUrl('search', [
            'record' => $this->getRecord(),
            'search' => $this->searchQuery,
        ]);
    }

    protected function getFooterWidgets(): array
    {
        // Only show the table widget if a search has been performed
        if (empty($this->searchQuery)) {
            return [];
        }

        $service = SearchService::new($this->searchQuery)->setProductSource($this->getRecord());
        $params = ['searchQuery' => $this->searchQuery, 'productSource' => $this->getRecord()];

        $widgets = [];

        if ($service->getIsComplete()) {
            $widgets[] = CreateViaSearchTable::make($params);
        }

        if ($service->getInProgress() || $service->getIsComplete()) {
            $widgets[] = ProductSourceScrapeDebugWidget::make($params);
        }

        return $widgets;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return [
            'sm' => 1,
            'xl' => 1,
        ];
    }

    protected function getFooterWidgetsData(): array
    {
        return [
            'searchQuery' => $this->searchQuery,
            'product' => null,
            'productSource' => $this->getRecord(),
        ];
    }
}
