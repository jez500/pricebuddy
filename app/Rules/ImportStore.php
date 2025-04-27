<?php

namespace App\Rules;

use App\Enums\ScraperService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ImportStore implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $json = json_decode($value, true);

        if (is_null($json)) {
            $fail('The JSON is invalid');
        }

        if (empty(data_get($json, 'name'))) {
            $fail('The JSON is missing a store name');
        }

        if (empty(data_get($json, 'domains')) || ! is_array(data_get($json, 'domains'))) {
            $fail('The JSON is missing domains');
        }

        if (empty(data_get($json, 'scrape_strategy.title.value')) || empty(data_get($json, 'scrape_strategy.title.type'))) {
            $fail('The JSON is missing a title strategy');
        }

        if (empty(data_get($json, 'scrape_strategy.price.value')) || empty(data_get($json, 'scrape_strategy.price.type'))) {
            $fail('The JSON is missing a price strategy');
        }

        if (! in_array(data_get($json, 'settings.scraper_service'), ScraperService::values())) {
            $fail('The scraper service is invalid');
        }
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
