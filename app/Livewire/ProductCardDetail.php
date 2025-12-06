<?php

namespace App\Livewire;

use App\Filament\Resources\ProductResource\Actions\AddUrlAction;
use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Livewire\Component;

class ProductCardDetail extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public Product $product;

    public bool $standalone = false;

    public bool $showChart = false;

    public function getRecord(): Product
    {
        return $this->product;
    }

    public function mount(Product $product, bool $standalone = false, bool $showChart = false): void
    {
        $this->product = $product;
        $this->standalone = $standalone;
        $this->showChart = $showChart;
    }

    public function addUrlAction(): Action
    {
        return AddUrlAction::make('addUrl')
            ->record($this->product)
            ->size('sm');
    }

    public function fetchAction(): Action
    {
        return Action::make('fetch')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-rocket-launch')
            ->outlined(false)
            ->action(function () {
                try {
                    UpdateAllPricesJob::dispatch([$this->product->id]);

                    Notification::make('fetched_prices')
                        ->title(__('Prices updating in the background'))
                        ->success()
                        ->send();

                    $this->dispatch('$refresh');
                } catch (Exception $e) {
                    Notification::make('fetch_failed')
                        ->title(__('Couldn\'t fetch the product, refer to logs'))
                        ->danger()
                        ->send();
                }
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-pencil')
            ->outlined(false)
            ->url(fn () => route('filament.admin.resources.products.edit', ['record' => $this->product->id]));
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->size('sm')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->outlined(false)
            ->requiresConfirmation(true)
            ->hidden(fn () => auth()->user()->cannot('delete', $this->product))
            ->authorize('delete', $this->product)
            ->action(function () {
                $this->product->delete();

                Notification::make('deleted_product')
                    ->title('Product deleted')
                    ->success()
                    ->send();

                return redirect('/admin');
            });
    }

    public function render()
    {
        return view('components.livewire.product-card-detail');
    }
}
