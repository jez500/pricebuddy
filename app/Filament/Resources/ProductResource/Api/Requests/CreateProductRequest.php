<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use App\Enums\Statuses;
use App\Filament\Resources\ProductResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required',
            'url' => 'required|url',
            // Only the caller's own products. Another user's product fails like a missing one.
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('user_id', $this->user()?->getKey())],
            'image' => 'nullable|url',
            'status' => 'sometimes|in:'.implode(',', Statuses::values()),
            'weight' => 'sometimes|numeric',
            'notify_price' => 'sometimes|numeric',
            'notify_percent' => 'sometimes|numeric',
            'favourite' => 'sometimes|boolean',
            'only_official' => 'nullable|boolean',
            'create_store' => 'sometimes|boolean',
            'notify_in_stock' => 'sometimes|boolean',
            'notify_on_change' => 'sometimes|boolean',
            'paused' => 'sometimes|boolean',
            'refresh_interval' => ['sometimes', 'nullable', 'integer', Rule::in(array_keys(ProductResource::REFRESH_INTERVALS))],
            ...self::variantRules(),
        ];
    }

    /**
     * Optional variant to track, eg `{"values": ["Small"]}`. Stock and price then come
     * from the matching variant instead of the whole page. `id` pins one exact variant,
     * eg its SKU.
     *
     * @return array<string, string>
     */
    public static function variantRules(): array
    {
        return [
            'variant' => 'nullable|array',
            'variant.values' => 'nullable|array|max:10',
            'variant.values.*' => 'string|max:255',
            'variant.id' => 'nullable|string|max:255',
        ];
    }
}
