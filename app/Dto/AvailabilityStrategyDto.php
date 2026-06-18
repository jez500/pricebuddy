<?php

namespace App\Dto;

use App\Enums\ScraperStrategyType;
use App\Enums\StockStatus;

/**
 * Availability scrape strategy: a standard slot plus per-status match rules and a
 * fallback status.
 */
class AvailabilityStrategyDto extends StandardStrategyDto
{
    /**
     * @param  array<string, AvailabilityMatchDto>  $match  keyed by StockStatus value
     */
    public function __construct(
        ScraperStrategyType $type,
        ?string $value = null,
        ?string $prepend = null,
        ?string $append = null,
        public array $match = [],
        public StockStatus $defaultStatus = StockStatus::InStock,
    ) {
        parent::__construct($type, $value, $prepend, $append);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $type = ScraperStrategyType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $rawMatch = is_array($data['match'] ?? null) ? $data['match'] : [];
        $defaultStatus = StockStatus::tryFrom((string) ($rawMatch['default'] ?? '')) ?? StockStatus::InStock;

        $match = [];
        foreach ($rawMatch as $statusValue => $entry) {
            if ($statusValue === 'default') {
                continue;
            }

            // Normalize legacy plain-string entries (e.g. 'out_of_stock' => 'Sold out')
            // into the canonical {type, value} shape before building the rule.
            if (is_string($entry) && $entry !== '') {
                $entry = ['type' => 'match', 'value' => $entry];
            }

            if (! is_array($entry)) {
                continue;
            }

            $rule = AvailabilityMatchDto::fromArray($entry);
            if ($rule !== null && StockStatus::tryFrom((string) $statusValue) !== null) {
                $match[(string) $statusValue] = $rule;
            }
        }

        return new self(
            type: $type,
            value: self::nullableString($data['value'] ?? null),
            prepend: self::nullableString($data['prepend'] ?? null),
            append: self::nullableString($data['append'] ?? null),
            match: $match,
            defaultStatus: $defaultStatus,
        );
    }

    /**
     * Rebuild the legacy match-config array consumed by StockStatus::matchFromScrapedValue(),
     * or null when no per-status rules are configured.
     *
     * @return array<string, array{type: string, value: string}|string>|null
     */
    public function matchConfig(): ?array
    {
        if ($this->match === []) {
            return null;
        }

        $out = [];
        foreach ($this->match as $statusValue => $rule) {
            $out[$statusValue] = $rule->toArray();
        }
        $out['default'] = $this->defaultStatus->value;

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = parent::toArray();

        $matchConfig = $this->matchConfig();
        if ($matchConfig !== null) {
            $out['match'] = $matchConfig;
        }

        return $out;
    }
}
