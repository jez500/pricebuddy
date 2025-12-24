<?php

namespace App\Rules;

use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCron implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        try {
            CronExpression::factory($value);
        } catch (\Exception $e) {
            $fail('The cron expression is invalid.');
        }
    }
}
