<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Where currency conversion rates come from.
 */
enum ExchangeRateProvider: string implements HasDescription, HasLabel
{
    case Frankfurter = 'frankfurter';

    case Yahoo = 'yahoo';

    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Frankfurter => 'Frankfurter (European Central Bank)',
            self::Yahoo => 'Yahoo Finance',
            self::Manual => 'Manual rates',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Frankfurter => 'Free daily reference rates published by the European Central Bank, about 30 major currencies. No API key needed.',
            self::Yahoo => 'Free daily rates for most currencies from Yahoo Finance. No API key needed, but it is an unofficial API and can rate limit.',
            self::Manual => 'Fixed rates you enter yourself. The same rate is used for every date.',
        };
    }

    /**
     * Whether rates are downloaded (and stored by date) rather than entered by hand.
     */
    public function isRemote(): bool
    {
        return $this !== self::Manual;
    }

    public static function fromSetting(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Frankfurter)
            : self::Frankfurter;
    }
}
