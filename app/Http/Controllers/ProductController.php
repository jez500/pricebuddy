<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * For things not provided by filament.
 */
class ProductController extends Controller
{
    public function fetch(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        try {
            $product->updatePrices();

            Notification::make()
                ->title('Fetched latest prices')
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title("Couldn't fetch the product, refer to logs")
                ->danger()
                ->send();
        }

        return redirect()->back();
    }
}
