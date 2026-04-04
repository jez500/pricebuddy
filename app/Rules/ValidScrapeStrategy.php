<?php

namespace App\Rules;

use App\Services\Helpers\ScrapeStrategyHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidScrapeStrategy implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an array.');

            return;
        }

        $result = ScrapeStrategyHelper::validate($value);

        if ($result !== true) {
            $fail('The :attribute has invalid field definitions: '.implode(', ', $result));
        }
    }
}
