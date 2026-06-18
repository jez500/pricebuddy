<?php

namespace App\Enums;

enum ApiAbility: string
{
    case MetaExtractionExtract = 'meta-extraction:extract';

    case UserDetail = 'user:detail';

    public function label(): string
    {
        return match ($this) {
            self::MetaExtractionExtract => 'Extract metadata from a URL',
            self::UserDetail => 'Read the authenticated account',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::MetaExtractionExtract => 'Meta extraction',
            self::UserDetail => 'Account',
        };
    }
}
