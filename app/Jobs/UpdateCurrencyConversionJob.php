<?php

namespace App\Jobs;

use App\Console\Commands\RegeneratePriceCache;
use App\Services\CurrencyConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Apply changed currency conversion settings: fetch any missing rates, then
 * rebuild every product's price cache so prices show in the new currency
 * (or back in each store's own currency when conversion was turned off).
 */
class UpdateCurrencyConversionJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 600;

    public function handle(): void
    {
        CurrencyConverter::new()->syncRates();

        Artisan::call(RegeneratePriceCache::COMMAND);
    }
}
