<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Enums\Icons;
use App\Jobs\CacheSearchResults;
use App\Models\Product;
use App\Services\Helpers\IntegrationHelper;
use App\Services\SearchService;
use Carbon\Carbon;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Livewire\Attributes\Url;

/**
 * @property Form $form
 */
class CreateViaSearchForm extends Widget implements HasForms
{
    use InteractsWithForms;

    protected $listeners = [
        'refreshSearchForm' => '$refresh',
    ];

    protected static string $view = 'filament.resources.product-resource.widgets.create-via-search';

    public ?array $data = [];

    #[Url]
    public ?string $searchQuery = null;

    public array $filters = [];

    public array $progressLog = [];

    public array $results = [];

    public ?Product $product = null;

    /**
     * Url of the page this component is embedded on.
     */
    public string $pageUrl = '/admin/products/create';

    public bool $showLog = false;

    /**
     * Timestamp of when the search job was started/completed.
     */
    public false|string $inProgress = false;

    public false|string $isComplete = false;

    public static function canView(): bool
    {
        return SearchService::canSearch();
    }

    public function mount(): void
    {
        // If the component is mounted from a product page, we want to set
        // the search query to the product title.
        if ($this->product && ! $this->searchQuery) {
            $this->searchQuery = $this->product->title;
            $this->pageUrl = $this->product->view_url;
        }

        // Populate the form and setup the log.
        $this->form->fill(['keyword' => $this->searchQuery]);
        $this->progressLog[] = ['message' => __('Ready to search'), 'timestamp' => now()];

        // If query exists, save that kicks off the search.
        if (! empty($this->searchQuery)) {
            $this->save();
        }
    }

    public function form(Form $form): Form
    {
        $settings = IntegrationHelper::getSearchSettings();
        $prefix = data_get($settings, 'search_prefix');

        return $form
            ->schema([
                Section::make('product_search')
                    ->description($this->product
                        ? null
                        : __('Search for a product on the web and create a new product from the search results.')
                    )
                    ->heading($this->product
                        ? null
                        : __('Search via the web'))
                    ->columns(1)
                    ->schema([
                        TextInput::make('keyword')
                            ->label(__('Product name'))
                            ->hintIcon(Icons::Help->value, $prefix
                                ? __('Search query will be prefixed with: ":prefix"', ['prefix' => $prefix])
                                : __('Search query to get results')
                            )
                            ->autocomplete()
                            ->required(),

                    ])
                    ->footerActions([
                        Action::make('search_now')
                            ->label(__('Start a search'))
                            ->icon('heroicon-m-magnifying-glass')
                            ->submit('form'),
                    ]),
            ])

            ->statePath('data');
    }

    public function save()
    {
        $this->form->validate();
        $formSearchQuery = $this->getSearchKeywordFromForm();

        // To prevent issues with livewire not refreshing the page we
        // simply redirect to the page to refresh the component.
        if ($this->searchQuery !== $formSearchQuery && ! empty($formSearchQuery) && ! empty($this->searchQuery)) {
            $this->redirect($this->pageUrl.'?searchQuery='.$formSearchQuery);

            return;
        }

        $this->searchQuery = $formSearchQuery;
        $this->showLog = ! empty($this->searchQuery);

        if (! $this->searchQuery) {
            return;
        }

        // Avoid empty log.
        $this->progressLog[] = ['message' => __('Preparing to search'), 'timestamp' => now()];

        $service = SearchService::new($this->searchQuery);

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

        CacheSearchResults::dispatch($this->searchQuery);

        Notification::make('searchJobDispatched')
            ->title(__('Search job dispatched'))
            ->success()
            ->send();
    }

    /**
     * Called on poll from the frontend.
     */
    public function refreshProgress(): void
    {
        $searchQuery = $this->searchQuery ?? $this->getSearchKeywordFromForm();

        if ($searchQuery && ! $this->isComplete) {
            $this->progressLog[] = ['message' => __('Refreshing progress for ":query"', ['query' => $searchQuery]), 'timestamp' => now()];

            $service = SearchService::new($searchQuery);
            $this->progressLog = $service->getLog();
            $this->inProgress = $service->getInProgress();
            $this->isComplete = $service->getIsComplete();
        }
    }

    protected function getSearchKeywordFromForm(): string
    {
        return data_get($this->data, 'keyword', '');
    }
}
