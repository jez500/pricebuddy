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

        $values = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== '',
        )));

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
     * A stable key for de-duplicating URLs tracked for the same variant.
     */
    public function key(): string
    {
        $values = array_map(VariantMatcher::normalize(...), $this->values);
        sort($values);

        return implode('|', $values).'#'.($this->id ?? '');
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
