<?php

namespace App\Rules;

use App\Services\AutoCreateStore;
use App\Services\ScrapeUrl;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreUrl implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $store = ScrapeUrl::new($value)->getStore();

        $shouldCreateStore = data_get($this->data, 'data.create_store', true);

        if (empty($value) || (empty($store) && ! $shouldCreateStore)) {
            $fail('The domain does not belong to any stores');
        }

        if ($store) {
            $scrape = ScrapeUrl::new($value)->scrape();

            $isUnavailable = ! empty($scrape['availability']);

            if (empty($scrape['title']) || (empty($scrape['price']) && ! $isUnavailable)) {
                $fail('The url does not contain a valid title or price');
            }
        } elseif ($shouldCreateStore && ! AutoCreateStore::canAutoCreateFromUrl($value)) {
            $fail('Unable to auto create store');
        }
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
