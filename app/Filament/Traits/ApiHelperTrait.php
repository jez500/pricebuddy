<?php

namespace App\Filament\Traits;

use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Support\Arr;

trait ApiHelperTrait
{
    public function getPerPage(): ?int
    {
        $default = (string) AdminPanelProvider::DEFAULT_PAGINATION[0];

        return (int) request()->query('per_page', $default);
    }

    /**
     * Collapse a Spatie filter value back into the single string the caller sent.
     *
     * Spatie splits every filter value on its array delimiter (default ','), so a
     * value legitimately containing a comma — Amazon's `sprefix=tv,aps,300`, a
     * faceted `?facets=colour,size` — arrives as an array. The bracket-array form
     * `filter[x][]=` nests that a second time. Flattening and rejoining with ','
     * reconstructs the original string exactly, because ',' is precisely what was
     * split on and the split does no trimming or empty-element dropping.
     *
     * Multiple distinct values (`filter[x][]=a&filter[x][]=b`) collapse to "a,b",
     * which callers are expected to reject as unparseable rather than treat as a
     * match — filtering on two values at once is not a supported query.
     */
    public function filterValueToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(static fn ($item): string => (string) $item, Arr::flatten($value)));
        }

        return (string) $value;
    }
}
