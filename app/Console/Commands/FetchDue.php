<?php

namespace App\Console\Commands;

use App\Services\PriceFetcherService;
use Illuminate\Console\Command;

class FetchDue extends Command
{
    const COMMAND = 'buddy:fetch-due';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {--log : Log the price fetching}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch prices for products that are due based on their custom check frequency';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        PriceFetcherService::new()
            ->setLogging((bool) $this->option('log'))
            ->updateDuePrices();

        return self::SUCCESS;
    }
}
