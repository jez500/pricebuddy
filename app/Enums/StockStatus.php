<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StockStatus: string implements HasColor, HasIcon, HasLabel
{
    case InStock = 'in_stock';
    case PreOrder = 'pre_order';
    case BackOrder = 'back_order';
    case SpecialOrder = 'special_order';
    case OutOfStock = 'out_of_stock';
    case Discontinued = 'discontinued';

    public function getLabel(): string
    {
        return match ($this) {
            self::InStock => 'In Stock',
            self::PreOrder => 'Pre-Order',
            self::BackOrder => 'Back Order',
            self::SpecialOrder => 'Special Order',
            self::OutOfStock => 'Out of Stock',
            self::Discontinued => 'Discontinued',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::PreOrder => 'info',
            self::BackOrder, self::SpecialOrder => 'warning',
            self::OutOfStock => 'danger',
            self::Discontinued => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::InStock => 'heroicon-m-check-circle',
            self::PreOrder => 'heroicon-m-clock',
            self::BackOrder => 'heroicon-m-arrow-path',
            self::SpecialOrder => 'heroicon-m-truck',
            self::OutOfStock => 'heroicon-m-x-circle',
            self::Discontinued => 'heroicon-m-archive-box-x-mark',
        };
    }

    public function getSortOrder(): int
    {
        return match ($this) {
            self::InStock => 0,
            self::PreOrder => 1,
            self::BackOrder => 2,
            self::SpecialOrder => 3,
            self::OutOfStock => 4,
            self::Discontinued => 5,
        };
    }

    /**
     * Whether this status represents an unavailable item (anything other than InStock).
     */
    public function isUnavailable(): bool
    {
        return $this !== self::InStock;
    }

    /**
     * Map a raw scraped value to a StockStatus enum case.
     */
    public static function fromScrapedValue(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::InStock;
        }

        // Try enum value string first (e.g. "out_of_stock").
        $fromEnum = self::tryFrom($value);
        if ($fromEnum !== null) {
            return $fromEnum;
        }

        return match ($value) {
            'OutOfStock' => self::OutOfStock,
            'PreOrder' => self::PreOrder,
            'BackOrder' => self::BackOrder,
            'SpecialOrder' => self::SpecialOrder,
            'Discontinued' => self::Discontinued,
            default => self::OutOfStock,
        };
    }

    /**
     * Resolve a scraped value against a match config to determine stock status.
     *
     * @param  array<string, array{type?: string, value?: string}|string|null>|string|null  $matchConfig
     */
    public static function matchFromScrapedValue(?string $scrapedValue, array|string|null $matchConfig): self
    {
        if ($scrapedValue === null || $scrapedValue === '') {
            return self::InStock;
        }

        // Legacy: flat string match → OutOfStock or InStock.
        if (is_string($matchConfig)) {
            return trim($scrapedValue) === trim($matchConfig) ? self::OutOfStock : self::InStock;
        }

        // Per-status match config: check each entry.
        if (is_array($matchConfig)) {
            $default = self::tryFrom($matchConfig['default'] ?? '') ?? self::InStock;

            foreach ($matchConfig as $statusValue => $matchEntry) {
                if ($statusValue === 'default' || $matchEntry === '' || $matchEntry === null) {
                    continue;
                }

                // Support both new { type, value } format and legacy plain string.
                if (is_array($matchEntry)) {
                    $type = $matchEntry['type'] ?? 'match';
                    $value = $matchEntry['value'] ?? '';

                    if ($value === '') {
                        continue;
                    }

                    $matched = self::valueMatches($scrapedValue, $value, $type);
                } else {
                    $matched = trim($scrapedValue) === trim((string) $matchEntry);
                }

                if ($matched) {
                    $status = self::tryFrom($statusValue);
                    if ($status !== null) {
                        return $status;
                    }
                }
            }

            return $default;
        }

        // No match config: any non-empty value → OutOfStock.
        return self::OutOfStock;
    }

    /**
     * Check if a scraped value matches using the given type.
     */
    private static function valueMatches(string $scrapedValue, string $value, string $type): bool
    {
        if ($type === 'regex') {
            $pattern = '~'.str_replace('~', '\~', $value).'~i';

            return (bool) @preg_match($pattern, $scrapedValue);
        }

        return trim($scrapedValue) === trim($value);
    }

    /**
     * All cases except InStock, for use in match config forms.
     *
     * @return array<int, self>
     */
    public static function nonInStockCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case !== self::InStock));
    }
}
