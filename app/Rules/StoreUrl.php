<?php

namespace App\Rules;

use App\Dto\VariantFilterDto;
use App\Enums\AiFeature;
use App\Services\AutoCreateStore;
use App\Services\Helpers\IntegrationHelper;
use App\Services\ScrapeUrl;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreUrl implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    /**
     * The variant the URL will track, if any. See self::forVariant().
     */
    protected ?VariantFilterDto $variant = null;

    /**
     * Validate a URL for the variant it will track, eg the variant field in the same
     * form. Without one the page as a whole needs a title and a price.
     *
     * @param  array<string, mixed>|null  $variant
     */
    public static function forVariant(?array $variant): self
    {
        $rule = new self;
        $rule->variant = VariantFilterDto::fromArray($variant);

        return $rule;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The url must be a valid URL starting with http:// or https://');

            return;
        }

        // When AI self-healing is enabled, defer store/scrape validation to
        // Url::createFromUrl, which builds or repairs the store config via AI and
        // surfaces its own error if it ultimately cannot. Only URL format is enforced here.
        if (IntegrationHelper::isFeatureEnabled(AiFeature::Healing)) {
            return;
        }

        $store = ScrapeUrl::new($value)->getStore();

        $shouldCreateStore = data_get($this->data, 'data.create_store', true);

        if (empty($store) && ! $shouldCreateStore) {
            $fail('The domain does not belong to any stores');
        }

        if ($store) {
            // Scrape for the variant the form tracks, so a page whose only prices are on
            // its variants is judged by the chosen variant's price and stock.
            $scrape = ScrapeUrl::new($value)->scrape(['variant' => $this->variant]);

            if (data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY)) {
                $fail(__('The page lists no variants for ":variant". Check the store\'s variants strategy.', [
                    'variant' => $this->variant?->label(),
                ]));

                return;
            }

            $availabilityStrategy = data_get($scrape, 'store.scrape_strategy.availability');
            $isUnavailable = ScrapeUrl::resolveStockStatus($scrape, $availabilityStrategy)->isUnavailable();

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
