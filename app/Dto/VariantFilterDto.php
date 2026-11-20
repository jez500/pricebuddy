<?php

namespace App\Dto;

use App\Services\VariantMatcher;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The variant a product URL tracks, eg size "Small", or size "10" plus colour "Black".
 *
 * Stored on `urls.variant`. Every value must equal one of a variant's option values.
 * The optional id pins one exact variant (eg a SKU picked from detected variants) and
 * wins over the values while the page still lists it.
 *
 * @implements Arrayable<string, mixed>
 */
class VariantFilterDto implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $values
     */
    public function __construct(
        public array $values = [],
        public ?string $id = null,
    ) {}

    /**
     * Build from a stored or submitted array, or null when it holds nothing to match on.
     *
     * Accepts `values` as a list or a single comma separated string, so API callers and
     * typed form input both work.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (! $data) {
            return null;
        }

        $values = $data['values'] ?? [];

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        $values = is_array($values) ? $values : [];

        // Drop blanks, and values that only differ in case or separators (eg "Small" and
        // "small"), keeping the first spelling.
        $values = collect($values)
            ->map(static fn ($value): string => is_scalar($value) ? trim((string) $value) : '')
            ->filter(static fn (string $value): bool => VariantMatcher::normalize($value) !== '')
            // Strict, so numeric text such as "01" and "1" stays distinct.
            ->unique(static fn (string $value): string => VariantMatcher::normalize($value), true)
            ->values()
            ->all();

        $id = is_scalar($data['id'] ?? null) ? trim((string) $data['id']) : '';
        $id = $id === '' ? null : $id;

        if ($values === [] && $id === null) {
            return null;
        }

        return new self($values, $id);
    }

    /**
     * Human readable label, eg "Small / Black".
     */
    public function label(): string
    {
        return $this->values !== [] ? implode(' / ', $this->values) : (string) $this->id;
    }

    /**
     * A stable key for comparing filters, eg to de-duplicate URLs tracked for the same
     * variant. Order, case and separators don't matter. JSON keeps the values apart from
     * each other and from the id, so no option text can collide with another filter.
     */
    public function key(): string
    {
        $values = array_values(array_unique(array_map(VariantMatcher::normalize(...), $this->values), SORT_STRING));
        // As text, so "01" and "1" sort the same way whatever order they were given in.
        sort($values, SORT_STRING);

        return (string) json_encode([$values, $this->id], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{values: array<int, string>, id?: string}
     */
    public function toArray(): array
    {
        $out = ['values' => $this->values];

        if ($this->id !== null) {
            $out['id'] = $this->id;
        }

        return $out;
    }

    /**
     * @return array{values: array<int, string>, id?: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
