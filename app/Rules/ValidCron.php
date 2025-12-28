<?php

namespace App\Rules;

use App\Services\Helpers\ScheduleHelper;
use Illuminate\Contracts\Validation\ValidationRule;
use Lorisleiva\CronTranslator\CronTranslator;

class ValidCron implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        try {
            CronTranslator::translate($value);
        } catch (\Exception $e) {
            $fail(ScheduleHelper::getInvalidCronText());
        }
    }
}
