<?php

namespace App\Services;

use App\Dto\AiExtractionResultDto;
use App\Dto\AiProviderConfigDto;
use App\Enums\StockStatus;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiExtractionService
{
    protected const int MAX_HTML_CHARS = 25000;

    /**
     * The extraction-fallback instructions, ported from PriceGhost's EXTRACTION_PROMPT.
     */
    protected const string EXTRACTION_PROMPT = <<<'PROMPT'
        You are a precise e-commerce data extraction assistant. Extract the product's
        current, purchasable price and details from the provided HTML.

        Rules:
        - Return the single price a customer would pay right now to buy the product.
        - Ignore crossed-out, "was", RRP, savings amounts, bundle totals, and per-unit prices.
        - If multiple variants exist, choose the default/selected variant.
        - currency must be an ISO 4217 code (e.g. USD, GBP, EUR) if determinable.
        - stockStatus must be a schema.org availability URL if determinable
          (e.g. https://schema.org/InStock or https://schema.org/OutOfStock).
        - confidence is your certainty from 0 to 1.
        - Use null for any field you cannot determine.
        PROMPT;

    public function __construct(protected AiService $ai) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * @param  Collection<int, mixed>|null  $schemaOrg
     */
    public function extract(string $html, ?Collection $schemaOrg = null, ?AiProviderConfigDto $provider = null): ?AiExtractionResultDto
    {
        $provider ??= IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return null;
        }

        $result = $this->ai->structured(
            self::EXTRACTION_PROMPT,
            fn (JsonSchema $schema) => [
                'name' => $schema->string(),
                'price' => $schema->number(),
                'currency' => $schema->string(),
                'imageUrl' => $schema->string(),
                'stockStatus' => $schema->string(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
            ],
            $this->prepareHtml($html, $schemaOrg),
            $provider,
        );

        if (blank($result)) {
            return null;
        }

        return new AiExtractionResultDto(
            title: $result['name'] ?? null,
            price: $this->parsePrice($result['price'] ?? null),
            currency: $result['currency'] ?? null,
            image: $result['imageUrl'] ?? null,
            stockStatus: $this->mapStockStatus($result['stockStatus'] ?? null),
            confidence: (float) ($result['confidence'] ?? 0.0),
        );
    }

    /**
     * Reduce HTML to the most price-relevant content within a token budget.
     * Port of PriceGhost's prepareHtmlForAI(): strip scripts/styles/meta and truncate.
     *
     * @param  Collection<int, mixed>|null  $schemaOrg
     */
    public function prepareHtml(string $html, ?Collection $schemaOrg = null): string
    {
        $cleaned = preg_replace(
            ['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is', '#<meta\b[^>]*>#is'],
            '',
            $html,
        ) ?? $html;

        $prefix = ($schemaOrg !== null && $schemaOrg->isNotEmpty())
            ? $schemaOrg->toJson().' '
            : '';

        // Reserve room for the schema.org prefix so it is never sliced mid-JSON, then
        // guard the combined length (covers the rare case the prefix alone exceeds the cap).
        $budget = max(0, self::MAX_HTML_CHARS - strlen($prefix));

        return Str::limit($prefix.Str::limit($cleaned, $budget, ''), self::MAX_HTML_CHARS, '');
    }

    protected function parsePrice(mixed $price): ?float
    {
        if (blank($price)) {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        $parsed = CurrencyHelper::toFloat((string) $price);

        // CurrencyHelper::toFloat returns 0.0 on an unparseable string; treat that as "no price".
        return $parsed > 0 ? (float) $parsed : null;
    }

    protected function mapStockStatus(?string $availability): ?StockStatus
    {
        if (blank($availability)) {
            return null;
        }

        // The model returns a schema.org availability URL or label; reduce to the bare
        // label (e.g. "InStock") and reuse the canonical scraped-value mapping so all
        // six stock states are handled, not just in/out of stock.
        $label = Str::afterLast(trim($availability), '/');

        return StockStatus::fromScrapedValue($label);
    }
}
