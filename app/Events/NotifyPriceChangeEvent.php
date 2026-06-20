<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyPriceChangeEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
