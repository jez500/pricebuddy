<?php

namespace App\Rules;

use App\Enums\StockStatus;
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
        if (empty($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The url must be a valid URL starting with http:// or https://');

            return;
        }

        $store = ScrapeUrl::new($value)->getStore();

        $shouldCreateStore = data_get($this->data, 'data.create_store', true);

        if (empty($store) && ! $shouldCreateStore) {
            $fail('The domain does not belong to any stores');
        }

        if ($store) {
            $scrape = ScrapeUrl::new($value)->scrape();

            $matchConfig = data_get($scrape, 'store.scrape_strategy.availability.match');
            $isUnavailable = StockStatus::matchFromScrapedValue($scrape['availability'] ?? null, $matchConfig)->isUnavailable();

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
