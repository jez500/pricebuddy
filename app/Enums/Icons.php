<?php

namespace App\Enums;

enum Icons: string
{
    case Help = 'heroicon-o-information-circle';
    case TrendUp = 'heroicon-m-arrow-trending-up';

    case TrendDown = 'heroicon-m-arrow-trending-down';

    case TrendNone = 'heroicon-m-arrow-long-right';

    case Min = 'heroicon-o-arrow-long-down';

    case Max = 'heroicon-o-arrow-long-up';

    case Delete = 'heroicon-o-trash';

    case View = 'heroicon-o-eye';

    case Edit = 'heroicon-o-pencil-square';

    case Add = 'heroicon-o-plus-circle';

    case Share = 'heroicon-o-share';

    case Import = 'heroicon-o-arrow-down-on-square';

    public static function getTrendIcon(?string $trend): string
    {
        return match ($trend) {
            'down' => Icons::TrendDown->value,
            'up' => Icons::TrendUp->value,
            default => Icons::TrendNone->value,
        };
    }
}
