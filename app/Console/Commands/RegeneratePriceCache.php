<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class RegeneratePriceCache extends Command
{
    const COMMAND = 'buddy:regenerate-price-cache';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND;

    /**
     * The console command description.
     */
    protected $description = 'Regenerate product price cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Product::all()
            ->each(fn (Product $product) => $this->components->task(
                '(ID: '.$product->getKey().') '.$product->title_short,
                fn () => tap($product, function (Product $product): void {
                    $product->updatePriceCache();
                    $product->updateInsightsCache();
                }))
            );

        return self::SUCCESS;
    }
}
