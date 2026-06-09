<?php

namespace App\Enums;

enum AiFeature: string
{
    case Extraction = 'extraction';
    case Healing = 'healing';

    public function label(): string
    {
        return match ($this) {
            self::Extraction => 'Extraction',
            self::Healing => 'Healing',
        };
    }
}
