<?php

namespace App\Enums;

/**
 * Why AI healing did not improve a meta-extraction result. Reported to API clients so
 * they can explain a worse-than-usual result instead of silently showing empty fields.
 */
enum HealingReason: string
{
    /** Not requested, the store opted out, or no healing provider is configured. */
    case Disabled = 'disabled';

    /** The deterministic scrape already produced a usable result. */
    case NotNeeded = 'not_needed';

    /** The request budget was exhausted, or too little of it remained to start. */
    case Timeout = 'timeout';

    /** Healing ran but errored or produced no usable config. */
    case Error = 'error';
}
