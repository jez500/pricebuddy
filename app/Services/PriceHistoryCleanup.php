<?php

namespace App\Services;

use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class PriceHistoryCleanup
{
    public const MAX_BATCH_SIZE = 1000;

    /**
     * Validate filters before applying them to raw records, never chart points.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function validateFilters(array $filters): array
    {
        $hasPrice = filled($filters['min_price'] ?? null) || filled($filters['max_price'] ?? null);
        $rules = [
            'listing_id' => [Rule::requiredIf($hasPrice), 'nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ];
        if (filled($filters['from'] ?? null)) {
            $rules['until'][] = 'after_or_equal:from';
        }
        if (filled($filters['min_price'] ?? null)) {
            $rules['max_price'][] = 'gte:min_price';
        }

        return Validator::make($filters, $rules, [
            'listing_id.required' => __('Choose a listing before filtering prices so the currency is unambiguous.'),
        ])->validate();
    }

    /**
     * A price range belongs to one listing and its store currency.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Price>
     */
    public function query(User $actor, Product $product, array $filters = []): Builder
    {
        $product = $product->newQuery()->whereKey($product->getKey())->firstOrFail();
        Gate::forUser($actor)->authorize('update', $product);
        $filters = $this->validateFilters($filters);
        $query = Price::query()->whereHas('url', fn (Builder $query) => $query->where('product_id', $product->getKey()));

        if (filled($filters['listing_id'] ?? null)) {
            /** @var ?Url $listing */
            $listing = $product->urls()->whereKey($filters['listing_id'])->first();
            // An applied filter can outlive its listing or product association.
            if (! $listing) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('url_id', $listing->getKey())->where('store_id', $listing->store_id);
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (filled($filters['until'] ?? null)) {
            $query->where('created_at', '<', Carbon::parse($filters['until'])->addDay()->startOfDay());
        }
        if (filled($filters['min_price'] ?? null)) {
            $query->where('price', '>=', $filters['min_price']);
        }
        if (filled($filters['max_price'] ?? null)) {
            $query->where('price', '<=', $filters['max_price']);
        }

        return $query;
    }

    /**
     * Seal the exact IDs shown in a confirmation; later scrapes cannot join it.
     *
     * @param  array<int, int|string>  $ids
     */
    public function snapshot(User $actor, Product $product, array $ids): string
    {
        $ids = $this->validateIds($ids);
        $found = $this->query($actor, $product)->whereKey($ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['selection' => __('Some selected records no longer belong to this product. Refresh the history and try again.')]);
        }

        return Crypt::encryptString(json_encode([
            'actor' => $actor->getKey(),
            'product' => $product->getKey(),
            'ids' => $ids,
            'expires' => now()->addMinutes(15)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Atomically remove the confirmed rows and rebuild this product's caches.
     */
    public function deleteSnapshot(User $actor, Product $product, string $snapshot): int
    {
        try {
            $selection = json_decode(Crypt::decryptString($snapshot), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['selection' => __('The deletion confirmation is invalid. Select the records again.')]);
        }
        if (! is_array($selection) || ($selection['actor'] ?? null) !== $actor->getKey() || ($selection['product'] ?? null) !== $product->getKey() || ($selection['expires'] ?? 0) < now()->getTimestamp()) {
            throw ValidationException::withMessages(['selection' => __('The deletion confirmation has expired or belongs to another product. Select the records again.')]);
        }
        $ids = $this->validateIds(is_array($selection['ids'] ?? null) ? $selection['ids'] : []);

        return $product->withPriceHistoryLock(function (Product $product) use ($actor, $ids): int {
            Gate::forUser($actor)->authorize('update', $product);
            $foreign = Price::query()->whereKey($ids)->whereDoesntHave('url', fn (Builder $query) => $query->where('product_id', $product->getKey()))->exists();
            if ($foreign) {
                throw ValidationException::withMessages(['selection' => __('The selected records have changed. Refresh the history and try again.')]);
            }

            $deleted = $this->query($actor, $product)->whereKey($ids)->delete();
            if ($deleted > 0) {
                $product->updatePriceCache();
                $product->updateInsightsCache();
            }

            return $deleted;
        });
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function validateIds(array $ids): array
    {
        $data = Validator::make(['selection' => $ids], [
            'selection' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'selection.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();

        return array_values(array_map(intval(...), $data['selection']));
    }
}
