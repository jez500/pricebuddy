<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A daily exchange rate: 1 unit of base_currency = rate units of quote_currency.
 *
 * @property string $source
 * @property string $base_currency
 * @property string $quote_currency
 * @property Carbon $date
 * @property float $rate
 */
class ExchangeRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rate' => 'float',
        ];
    }
}
