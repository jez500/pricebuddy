<?php

namespace App\Console\Commands;

use App\Services\CurrencyConverter;
use Illuminate\Console\Command;

class FetchExchangeRates extends Command
{
    const COMMAND = 'buddy:fetch-exchange-rates';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {--no-rebuild : Do not rebuild the product price caches after fetching}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch exchange rates for currency conversion and rebuild price caches when they change';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $converter = CurrencyConverter::new();

        if (! $converter->isEnabled()) {
            $this->components->info('Currency conversion is disabled.');

            return self::SUCCESS;
        }

        if (! $converter->provider()->isRemote()) {
            $this->components->info('Currency conversion uses manual rates, nothing to fetch.');

            return self::SUCCESS;
        }

        $results = $converter->syncRates();

        if ($results === []) {
            $this->components->info('No store uses a currency other than '.$converter->targetCurrency().'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Currency', 'New rates', 'Changed rates', 'Error'],
            collect($results)->map(fn (array $result, string $currency): array => [
                $currency.' → '.$converter->targetCurrency(),
                $result['added'],
                $result['changed'],
                $result['error'] ?? '',
            ])->values()->all()
        );

        if (CurrencyConverter::ratesChanged($results) && ! $this->option('no-rebuild')) {
            $this->call(RegeneratePriceCache::COMMAND);
        }

        return collect($results)->every(fn (array $result): bool => $result['error'] !== null)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
