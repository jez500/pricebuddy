<?php

namespace App\Enums;

enum ApiAbility: string
{
    case MetaExtractionExtract = 'meta-extraction:extract';

    case UserDetail = 'user:detail';

    case ClientConfigRead = 'client-config:read';

    public function label(): string
    {
        return match ($this) {
            self::MetaExtractionExtract => 'Extract metadata from a URL',
            self::UserDetail => 'Read the authenticated account',
            self::ClientConfigRead => 'Read client capability configuration',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::MetaExtractionExtract => 'Meta extraction',
            self::UserDetail => 'Account',
            self::ClientConfigRead => 'Client config',
        };
    }
}
