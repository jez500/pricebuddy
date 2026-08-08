<?php

namespace App\Rules;

use App\Enums\ScraperStrategyType;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Validates a scrape strategy's `value` against its sibling `type`.
 *
 * `schema_org` reads the page's embedded metadata and takes no value, so one must not be
 * given; every other type needs a non-empty extraction expression. Shared by
 * meta-extraction and the store write endpoints, which previously disagreed: the
 * extension could test a schema_org strategy and then fail to save the identical payload.
 *
 * The sibling `type` is located relative to the attribute under validation, so the same
 * rule works at any path (`scrape_strategy.title.value`,
 * `store.scrape_strategy.title.value`).
 */
class ScrapeStrategyValue implements DataAwareRule, ValidationRule
{
    /**
     * Run even when `value` is absent — that is both how a schema_org strategy is
     * legitimately sent and how a selector with no expression must be caught.
     */
    public bool $implicit = true;

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = data_get($this->data, Str::beforeLast($attribute, '.').'.type');

        // No type in this payload (an optional strategy the client simply did not send, or
        // a partial update), so there is nothing to require a value for. A value sent on
        // its own must still be usable if it is there at all.
        if (blank($type)) {
            if (! is_null($value) && ! $this->isNonEmptyString($value)) {
                $fail("The {$attribute} field must be a non-empty string.");
            }

            return;
        }

        if (! ScraperStrategyType::needsValue((string) $type)) {
            if (! is_null($value)) {
                $fail("The {$attribute} field must be null when using {$type}.");
            }

            return;
        }

        if (is_null($value)) {
            $fail("The {$attribute} field is required when using {$type}.");

            return;
        }

        if (! $this->isNonEmptyString($value)) {
            $fail("The {$attribute} field must be a non-empty string.");
        }
    }

    protected function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
