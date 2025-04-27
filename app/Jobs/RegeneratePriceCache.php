<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RegeneratePriceCache implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        Artisan::call('buddy:regenerate-price-cache');
    }
}
