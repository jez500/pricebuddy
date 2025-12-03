<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ProductSourceType: string implements HasDescription, HasLabel
{
    case DealsSite = 'deals_site';
    case OnlineStore = 'online_store';

    public function getLabel(): string
    {
        return match ($this) {
            self::DealsSite => 'Deals Site (Aggregator)',
            self::OnlineStore => 'Online Store',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::DealsSite => 'Site that aggregates deals/links (e.g. OzBargain)',
            self::OnlineStore => 'Site that sells products directly (e.g. Amazon)',
        };
    }
}
