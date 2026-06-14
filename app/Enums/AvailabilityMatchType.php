<?php

namespace App\Enums;

enum AvailabilityMatchType: string
{
    case Match = 'match';

    case Regex = 'regex';
}
