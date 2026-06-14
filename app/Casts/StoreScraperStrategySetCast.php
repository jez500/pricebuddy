<?php

namespace App\Casts;

use App\Dto\StoreScraperStrategySetDto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the stores.scrape_strategy JSON column to/from a StoreScraperStrategySetDto.
 *
 * @implements CastsAttributes<StoreScraperStrategySetDto, StoreScraperStrategySetDto|array<string, mixed>>
 */
class StoreScraperStrategySetCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): StoreScraperStrategySetDto
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return StoreScraperStrategySetDto::fromArray(is_array($decoded) ? $decoded : null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof StoreScraperStrategySetDto) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode(StoreScraperStrategySetDto::fromArray($value)->toArray());
        }

        return json_encode(StoreScraperStrategySetDto::fromArray(null)->toArray());
    }
}
